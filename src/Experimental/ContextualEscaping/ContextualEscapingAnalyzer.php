<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Experimental\ContextualEscaping;

use Twig\Node\AutoEscapeNode;
use Twig\Node\BlockNode;
use Twig\Node\BlockReferenceNode;
use Twig\Node\BodyNode;
use Twig\Node\CaptureNode;
use Twig\Node\CheckSecurityCallNode;
use Twig\Node\CheckSecurityNode;
use Twig\Node\ConfigNode;
use Twig\Node\DeprecatedNode;
use Twig\Node\DoNode;
use Twig\Node\EmbedNode;
use Twig\Node\EmptyNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\BlockReferenceExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\MacroReferenceExpression;
use Twig\Node\Expression\OperatorEscapeInterface;
use Twig\Node\Expression\ParentExpression;
use Twig\Node\FlushNode;
use Twig\Node\ForElseNode;
use Twig\Node\ForNode;
use Twig\Node\IfNode;
use Twig\Node\ImportNode;
use Twig\Node\IncludeNode;
use Twig\Node\MacroDeclarationNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\PrintNode;
use Twig\Node\SetNode;
use Twig\Node\TextNode;
use Twig\Node\TypesNode;
use Twig\Node\WithNode;

/**
 * @internal
 *
 * @experimental
 */
final class ContextualEscapingAnalyzer
{
    private AnalysisResult $result;

    /** @var array<string, true> */
    private array $diagnosticKeys = [];

    public function __construct(
        private HtmlContextParser $contextParser,
    ) {
    }

    public function analyze(ModuleNode $module): AnalysisResult
    {
        $this->result = new AnalysisResult();
        $this->diagnosticKeys = [];
        $hasUnsupportedComposition = false;

        if ($module->hasNode('parent')) {
            $this->addDiagnostic($module, DiagnosticCode::UnsupportedTemplateComposition, 'Template inheritance is not supported by experimental contextual escaping analysis.');
            $hasUnsupportedComposition = true;
        }
        if (\count($module->getNode('traits'))) {
            $this->addDiagnostic($module, DiagnosticCode::UnsupportedTemplateComposition, 'Template traits are not supported by experimental contextual escaping analysis.');
            $hasUnsupportedComposition = true;
        }
        $this->collectIndependentDiagnostics($module->getNode('display_start'));
        $this->collectModuleIndependentDiagnostics($module);
        $this->collectIndependentDiagnostics($module->getNode('display_end'));

        $context = new HtmlContext();
        $context = $this->analyzeNode($module->getNode('display_start'), $context);
        $context = $this->analyzeNode($module->getNode('body'), $context);
        $context = $this->analyzeNode($module->getNode('display_end'), $context);

        if ($hasUnsupportedComposition) {
            $context = $context->toDead();
        }

        if (HtmlState::Text !== $context->getState() && HtmlState::Dead !== $context->getState()) {
            $line = $module->getSourceContext() ? 1 + substr_count($module->getSourceContext()->getCode(), "\n") : $module->getTemplateLine();
            $this->result->addDiagnostic(new Diagnostic(
                DiagnosticCode::IncompleteHtmlContext,
                \sprintf('The template ends in %s instead of HTML text.', $context->describe()),
                $line,
                $module->getTemplateName(),
            ));
        }

        return $this->result;
    }

    private function analyzeNode(Node $node, HtmlContext $context, string|bool|null $explicitAutoescape = null): HtmlContext
    {
        if (HtmlState::Dead === $context->getState()) {
            return $context;
        }

        return match ($node::class) {
            BodyNode::class, Nodes::class => $this->analyzeSequence($node, $context, $explicitAutoescape),
            EmptyNode::class => $context,
            TextNode::class => $this->contextParser->consume($context, $node->getAttribute('data')),
            PrintNode::class => $this->analyzePrint($node, $context, $explicitAutoescape),
            IfNode::class => $this->analyzeIf($node, $context, $explicitAutoescape),
            ForNode::class => $this->analyzeFor($node, $context, $explicitAutoescape),
            ForElseNode::class => $this->analyzeNode($node->getNode('body'), $context, $explicitAutoescape),
            WithNode::class => $this->analyzeNode($node->getNode('body'), $context, $explicitAutoescape),
            AutoEscapeNode::class => $this->analyzeAutoEscape($node, $context),
            SetNode::class => $this->analyzeSet($node, $context),
            BlockReferenceNode::class, ImportNode::class, IncludeNode::class, EmbedNode::class, BlockNode::class, MacroDeclarationNode::class, CaptureNode::class => $this->rejectCompositionNode($node, $context),
            CheckSecurityCallNode::class, CheckSecurityNode::class, ConfigNode::class, DeprecatedNode::class, DoNode::class, FlushNode::class, TypesNode::class => $context,
            default => $this->rejectUnknownNode($node, $context),
        };
    }

    private function analyzeSequence(Node $nodes, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        foreach ($nodes as $node) {
            $context = $this->analyzeNode($node, $context, $explicitAutoescape);
            if (HtmlState::Dead === $context->getState()) {
                break;
            }
        }

        return $context;
    }

    private function analyzePrint(PrintNode $node, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        /** @var AbstractExpression $expression */
        $expression = $node->getNode('expr');

        if ($expression->isGenerator()) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedOutputContext, 'Generator output is not supported by experimental contextual escaping analysis.');

            return $context->toDead();
        }

        if ($expression instanceof ConstantExpression && !$expression->isDefinedTestEnabled() && \is_string($expression->getAttribute('value'))) {
            $this->result->addInferredEscape(new InferredEscape($node, new EscapePlan([])));

            return $this->contextParser->consume($context, $expression->getAttribute('value'));
        }

        $context = $context->nudgeAttributeValue();
        $plan = $this->inferPlan($node, $context);
        if (null === $plan) {
            return $this->contextAfterUnsupportedPrint($context);
        }

        $this->result->addInferredEscape(new InferredEscape($node, $plan));

        $invalidatesContext = false;
        if ($this->containsRawFilter($expression)) {
            $this->addDiagnostic($node, DiagnosticCode::RawOutput, 'The "raw" filter cannot be verified until typed safe content is implemented.');
            $invalidatesContext = true;
        }
        if ($this->containsUnsupportedComposition($expression)) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'Template, block, parent block, and macro results are not supported by experimental contextual escaping analysis.');
            $invalidatesContext = true;
        }

        $contextDescription = $this->describePlan($plan);
        if (null !== $explicitAutoescape && false !== $explicitAutoescape) {
            $strategy = true === $explicitAutoescape ? 'html' : $explicitAutoescape;
            if (!$this->isExplicitStrategyCompatible($plan, $strategy)) {
                $this->addDiagnostic($node, DiagnosticCode::MismatchedExplicitEscaping, \sprintf('The explicit "%s" autoescaping strategy does not satisfy the inferred %s context.', $strategy, $contextDescription));
                $invalidatesContext = true;
            }
        }

        foreach ($this->findExplicitEscapingStrategies($expression) as [$strategy, $line]) {
            if (!$this->isExplicitStrategyCompatible($plan, $strategy)) {
                $this->result->addDiagnostic(new Diagnostic(
                    DiagnosticCode::MismatchedExplicitEscaping,
                    \sprintf('The explicit "%s" escaping strategy does not satisfy the inferred %s context.', $strategy ?? 'dynamic', $contextDescription),
                    $line,
                    $node->getTemplateName(),
                ));
                $invalidatesContext = true;
            }
        }

        return $invalidatesContext ? $context->toDead() : $context;
    }

    private function inferPlan(PrintNode $node, HtmlContext $context): ?EscapePlan
    {
        if ($context->getState()->isScriptData()) {
            return $this->rejectOutputContext($node, $context);
        }

        return match ($context->getState()) {
            HtmlState::Text => new EscapePlan([EscapeOperation::HtmlText]),
            HtmlState::Rcdata => new EscapePlan([EscapeOperation::HtmlRcdata]),
            HtmlState::AttributeValueDoubleQuoted, HtmlState::AttributeValueSingleQuoted => $this->inferAttributePlan($node, $context, false),
            HtmlState::AttributeValueUnquoted => $this->inferAttributePlan($node, $context, true),
            HtmlState::Comment, HtmlState::CommentStart, HtmlState::CommentStartDash, HtmlState::CommentEndDash, HtmlState::CommentEnd, HtmlState::CommentEndBang => $this->rejectCommentInterpolation($node),
            HtmlState::RawText, HtmlState::Plaintext => $this->rejectOutputContext($node, $context),
            default => $this->rejectStructuralInterpolation($node, $context),
        };
    }

    private function inferAttributePlan(PrintNode $node, HtmlContext $context, bool $unquoted): ?EscapePlan
    {
        $analysis = match ($context->getAttributeType()) {
            HtmlAttributeType::Url => 'URL',
            HtmlAttributeType::Srcset => 'srcset',
            HtmlAttributeType::Style => 'CSS',
            HtmlAttributeType::JavaScript => 'JavaScript',
            HtmlAttributeType::Html => 'embedded HTML',
            HtmlAttributeType::MetaContent => 'meta refresh',
            HtmlAttributeType::None => 'unknown contextual',
            HtmlAttributeType::Plain => null,
        };
        if (null !== $analysis) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedAttributeContext, \sprintf('Output in the "%s" attribute requires %s analysis, which is not implemented yet.', $context->getAttributeName(), $analysis));

            return null;
        }

        return new EscapePlan([$unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute]);
    }

    private function analyzeIf(IfNode $node, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        $branches = [];
        $tests = $node->getNode('tests');
        for ($i = 1; $i < \count($tests); $i += 2) {
            if ($tests->hasNode((string) $i)) {
                $branches[] = $this->analyzeNode($tests->getNode((string) $i), $context, $explicitAutoescape);
            } else {
                $branches[] = $context;
            }
        }
        $branches[] = $node->hasNode('else') ? $this->analyzeNode($node->getNode('else'), $context, $explicitAutoescape) : $context;

        return $this->joinContexts($branches, $node, 'The branches of this "if" tag end in incompatible contexts');
    }

    private function analyzeFor(ForNode $node, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        $bodyContext = $this->analyzeNode($node->getNode('body'), $context, $explicitAutoescape);
        if (HtmlState::Dead === $bodyContext->getState()) {
            return $bodyContext;
        }

        $input = $context->nudgeAttributeValue();
        $output = $bodyContext->nudgeAttributeValue();
        if (!$input->equals($output)) {
            $this->addDiagnostic($node, DiagnosticCode::UnstableLoop, \sprintf('The "for" loop body changes the HTML context from %s to %s, so repeated iterations cannot be analyzed safely.', $input->describe(), $output->describe()));

            return $context->toDead();
        }

        if (!$node->hasNode('else')) {
            return $output;
        }

        $elseContext = $this->analyzeNode($node->getNode('else'), $context, $explicitAutoescape);

        return $this->joinContexts([$output, $elseContext], $node, 'The body and "else" branch of this "for" tag end in incompatible contexts');
    }

    private function analyzeAutoEscape(AutoEscapeNode $node, HtmlContext $context): HtmlContext
    {
        $strategy = $node->getAttribute('value');
        if (false === $strategy) {
            $this->addDiagnostic($node, DiagnosticCode::DisabledAutoescaping, 'Disabling autoescaping cannot be verified until typed safe content is implemented.');
        }

        $bodyContext = $this->analyzeNode($node->getNode('body'), $context, $strategy);

        return false === $strategy ? $bodyContext->toDead() : $bodyContext;
    }

    private function analyzeSet(SetNode $node, HtmlContext $context): HtmlContext
    {
        if ($node->getAttribute('capture') || $node->getAttribute('safe')) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'Captured output is not supported until typed safe content is implemented.');
        }

        return $context;
    }

    /**
     * @param list<HtmlContext> $contexts
     */
    private function joinContexts(array $contexts, Node $node, string $message): HtmlContext
    {
        foreach ($contexts as $context) {
            if (HtmlState::Dead === $context->getState()) {
                return $context;
            }
        }

        $joined = $contexts[0]->nudgeAttributeValue();
        foreach (\array_slice($contexts, 1) as $context) {
            $context = $context->nudgeAttributeValue();
            if (!$joined->equals($context)) {
                $this->addDiagnostic($node, DiagnosticCode::AmbiguousControlFlow, \sprintf('%s: %s and %s.', $message, $joined->describe(), $context->describe()));

                return $joined->toDead();
            }
        }

        return $joined;
    }

    private function rejectCompositionNode(Node $node, HtmlContext $context): HtmlContext
    {
        $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, \sprintf('The "%s" node is not supported by experimental contextual escaping analysis.', $node::class));

        return $context->toDead();
    }

    private function rejectUnknownNode(Node $node, HtmlContext $context): HtmlContext
    {
        $this->addDiagnostic($node, DiagnosticCode::UnsupportedNode, \sprintf('The "%s" node has no contextual escaping analyzer.', $node::class));

        return $context->toDead();
    }

    private function rejectCommentInterpolation(PrintNode $node): null
    {
        $this->addDiagnostic($node, DiagnosticCode::CommentInterpolation, 'Output expressions inside HTML comments are not supported.');

        return null;
    }

    private function rejectOutputContext(PrintNode $node, HtmlContext $context): null
    {
        $this->addDiagnostic($node, DiagnosticCode::UnsupportedOutputContext, \sprintf('Output in %s requires language-specific analysis, which is not implemented yet.', $context->describe()));

        return null;
    }

    private function rejectStructuralInterpolation(PrintNode $node, HtmlContext $context): null
    {
        $this->addDiagnostic($node, DiagnosticCode::UnsupportedStructuralInterpolation, \sprintf('Output expressions in %s are not supported.', $context->describe()));

        return null;
    }

    private function contextAfterUnsupportedPrint(HtmlContext $context): HtmlContext
    {
        if ($context->getState()->isScriptData()) {
            return $context;
        }

        return match ($context->getState()) {
            HtmlState::Comment, HtmlState::CommentStart, HtmlState::CommentStartDash, HtmlState::CommentEndDash, HtmlState::CommentEnd, HtmlState::CommentEndBang,
            HtmlState::RawText, HtmlState::Plaintext,
            HtmlState::AttributeValueDoubleQuoted, HtmlState::AttributeValueSingleQuoted, HtmlState::AttributeValueUnquoted => $context,
            default => $context->toDead(),
        };
    }

    private function containsRawFilter(Node $node): bool
    {
        if ($node instanceof FilterExpression && 'raw' === $node->getAttribute('twig_callable')->getName()) {
            return true;
        }

        foreach ($node as $child) {
            if ($this->containsRawFilter($child)) {
                return true;
            }
        }

        return false;
    }

    private function containsUnsupportedComposition(Node $node): bool
    {
        if ($node instanceof BlockReferenceExpression || $node instanceof MacroReferenceExpression || $node instanceof ParentExpression) {
            return true;
        }
        if ($node instanceof FunctionExpression && 'include' === $node->getAttribute('twig_callable')->getName()) {
            return true;
        }

        foreach ($node as $child) {
            if ($this->containsUnsupportedComposition($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{string|null, int}>
     */
    private function findExplicitEscapingStrategies(Node $node): array
    {
        if ($node instanceof FilterExpression) {
            if ($this->isAutomaticEscape($node)) {
                return $this->findExplicitEscapingStrategies($node->getNode('node'));
            }
            if (!\in_array($node->getAttribute('twig_callable')->getName(), ['e', 'escape'], true)) {
                return [];
            }

            $arguments = $node->getNode('arguments');
            if (!\count($arguments)) {
                $strategy = 'html';
            } else {
                $first = $arguments->getNode(0);
                $strategy = $first instanceof ConstantExpression && \is_string($first->getAttribute('value')) ? $first->getAttribute('value') : null;
            }

            return [[$strategy, $node->getTemplateLine()]];
        }

        if (!$node instanceof OperatorEscapeInterface) {
            return [];
        }

        $strategies = [];
        foreach ($node->getOperandNamesToEscape() as $name) {
            array_push($strategies, ...$this->findExplicitEscapingStrategies($node->getNode($name)));
        }

        return $strategies;
    }

    private function isAutomaticEscape(FilterExpression $node): bool
    {
        $arguments = $node->getNode('arguments');

        return 2 < \count($arguments) && $arguments->getNode(2) instanceof ConstantExpression && true === $arguments->getNode(2)->getAttribute('value');
    }

    private function describePlan(EscapePlan $plan): string
    {
        return match ($plan->getOperations()) {
            [EscapeOperation::HtmlText] => 'HTML text',
            [EscapeOperation::HtmlRcdata] => 'HTML RCDATA',
            [EscapeOperation::HtmlAttribute] => 'quoted HTML attribute',
            [EscapeOperation::HtmlAttributeUnquoted] => 'unquoted HTML attribute',
            default => 'unknown',
        };
    }

    private function isExplicitStrategyCompatible(EscapePlan $plan, ?string $strategy): bool
    {
        return match ($plan->getOperations()) {
            [EscapeOperation::HtmlText], [EscapeOperation::HtmlRcdata] => 'html' === $strategy,
            [EscapeOperation::HtmlAttribute] => 'html_attr' === $strategy,
            default => false,
        };
    }

    private function collectModuleIndependentDiagnostics(ModuleNode $module): void
    {
        $this->collectIndependentDiagnostics($module->getNode('body'));

        foreach (['blocks', 'macros'] as $name) {
            foreach ($module->getNode($name) as $definition) {
                $this->collectDefinitionIndependentDiagnostics($definition);
            }
        }

        foreach ($module->getAttribute('embedded_templates') as $embeddedTemplate) {
            if ($embeddedTemplate instanceof ModuleNode) {
                $this->collectModuleIndependentDiagnostics($embeddedTemplate);
            }
        }
    }

    private function collectDefinitionIndependentDiagnostics(Node $node): void
    {
        if ($node->hasNode('body')) {
            $this->collectIndependentDiagnostics($node->getNode('body'));

            return;
        }

        foreach ($node as $child) {
            $this->collectDefinitionIndependentDiagnostics($child);
        }
    }

    private function collectIndependentDiagnostics(Node $node): void
    {
        switch ($node::class) {
            case BodyNode::class:
            case Nodes::class:
                foreach ($node as $child) {
                    $this->collectIndependentDiagnostics($child);
                }

                return;

            case PrintNode::class:
                /** @var AbstractExpression $expression */
                $expression = $node->getNode('expr');
                if ($expression->isGenerator()) {
                    $this->addDiagnostic($node, DiagnosticCode::UnsupportedOutputContext, 'Generator output is not supported by experimental contextual escaping analysis.');
                }
                if ($this->containsRawFilter($expression)) {
                    $this->addDiagnostic($node, DiagnosticCode::RawOutput, 'The "raw" filter cannot be verified until typed safe content is implemented.');
                }
                if ($this->containsUnsupportedComposition($expression)) {
                    $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'Template, block, parent block, and macro results are not supported by experimental contextual escaping analysis.');
                }

                return;

            case IfNode::class:
                $tests = $node->getNode('tests');
                for ($i = 0; $i < \count($tests); $i += 2) {
                    if ($tests->hasNode((string) $i)) {
                        $this->collectCompositionDiagnostic($tests->getNode((string) $i));
                    }
                    if ($tests->hasNode((string) (1 + $i))) {
                        $this->collectIndependentDiagnostics($tests->getNode((string) (1 + $i)));
                    }
                }
                if ($node->hasNode('else')) {
                    $this->collectIndependentDiagnostics($node->getNode('else'));
                }

                return;

            case ForNode::class:
                $this->collectCompositionDiagnostic($node->getNode('seq'));
                $this->collectIndependentDiagnostics($node->getNode('body'));
                if ($node->hasNode('else')) {
                    $this->collectIndependentDiagnostics($node->getNode('else'));
                }

                return;

            case ForElseNode::class:
                $this->collectIndependentDiagnostics($node->getNode('body'));

                return;

            case WithNode::class:
                if ($node->hasNode('variables')) {
                    $this->collectCompositionDiagnostic($node->getNode('variables'));
                }
                $this->collectIndependentDiagnostics($node->getNode('body'));

                return;

            case AutoEscapeNode::class:
                if (false === $node->getAttribute('value')) {
                    $this->addDiagnostic($node, DiagnosticCode::DisabledAutoescaping, 'Disabling autoescaping cannot be verified until typed safe content is implemented.');
                }
                $this->collectIndependentDiagnostics($node->getNode('body'));

                return;

            case SetNode::class:
                if ($node->getAttribute('capture') || $node->getAttribute('safe')) {
                    $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'Captured output is not supported until typed safe content is implemented.');
                    $values = $node->getNode('values');
                    if ($values instanceof CaptureNode && $values->hasNode('body')) {
                        $this->collectIndependentDiagnostics($values->getNode('body'));
                    }
                } elseif ($node->hasNode('values')) {
                    $this->collectCompositionDiagnostic($node->getNode('values'));
                }

                return;

            case DoNode::class:
                $this->collectCompositionDiagnostic($node->getNode('expr'));

                return;

            case DeprecatedNode::class:
                foreach ($node as $child) {
                    $this->collectCompositionDiagnostic($child);
                }

                return;

            case BlockReferenceNode::class:
            case ImportNode::class:
            case IncludeNode::class:
            case EmbedNode::class:
            case BlockNode::class:
            case MacroDeclarationNode::class:
                $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, \sprintf('The "%s" node is not supported by experimental contextual escaping analysis.', $node::class));

                return;

            case CaptureNode::class:
                $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'Captured output is not supported until typed safe content is implemented.');
                if ($node->hasNode('body')) {
                    $this->collectIndependentDiagnostics($node->getNode('body'));
                }

                return;

            case EmptyNode::class:
            case TextNode::class:
            case CheckSecurityCallNode::class:
            case CheckSecurityNode::class:
            case ConfigNode::class:
            case FlushNode::class:
            case TypesNode::class:
                return;

            default:
                $this->addDiagnostic($node, DiagnosticCode::UnsupportedNode, \sprintf('The "%s" node has no contextual escaping analyzer.', $node::class));
        }
    }

    private function collectCompositionDiagnostic(Node $node): void
    {
        if ($this->containsUnsupportedComposition($node)) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'Template, block, parent block, and macro results are not supported by experimental contextual escaping analysis.');
        }
    }

    private function addDiagnostic(Node $node, DiagnosticCode $code, string $message): void
    {
        $key = spl_object_id($node).':'.$code->name;
        if (isset($this->diagnosticKeys[$key])) {
            return;
        }
        $this->diagnosticKeys[$key] = true;
        $this->result->addDiagnostic(new Diagnostic($code, $message, $node->getTemplateLine(), $node->getTemplateName()));
    }
}
