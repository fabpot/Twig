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
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\BlockReferenceExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\MacroReferenceExpression;
use Twig\Node\Expression\OperatorEscapeInterface;
use Twig\Node\Expression\ParentExpression;
use Twig\Node\Expression\SupportDefinedTestInterface;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\Expression\Variable\MacroVariable;
use Twig\Node\FlushNode;
use Twig\Node\ForElseNode;
use Twig\Node\ForNode;
use Twig\Node\IfNode;
use Twig\Node\ImportNode;
use Twig\Node\IncludeNode;
use Twig\Node\MacroDeclarationNode;
use Twig\Node\MacroNode;
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

    /**
     * @var list<array{
     *     modules: list<ModuleNode>,
     *     blocks: array<string, list<array{module: ModuleNode, node: BlockNode}>>,
     *     imports: array<string, ModuleNode>
     * }>
     */
    private array $compositionScopes = [];

    /** @var list<ModuleNode> */
    private array $moduleStack = [];

    /** @var list<array{name: string, definitions: list<array{module: ModuleNode, node: BlockNode}>, index: int}> */
    private array $blockStack = [];

    /** @var array<int, ModuleNode> */
    private array $embeddedModules = [];

    /** @var array<int, true> */
    private array $activeModules = [];

    /** @var array<string, true> */
    private array $activeBlocks = [];

    /** @var array<string, true> */
    private array $activeMacros = [];

    public function __construct(
        private HtmlContextParser $contextParser,
        private ?TemplateResolverInterface $templateResolver = null,
    ) {
    }

    public function analyze(ModuleNode $module): AnalysisResult
    {
        $this->result = new AnalysisResult();
        $this->diagnosticKeys = [];
        $this->compositionScopes = [];
        $this->moduleStack = [];
        $this->blockStack = [];
        $this->embeddedModules = [];
        $this->activeModules = [];
        $this->activeBlocks = [];
        $this->activeMacros = [];

        $context = $this->analyzeModule($module, new HtmlContext(), $module);

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

    private function analyzeModule(ModuleNode $module, HtmlContext $context, Node $origin): HtmlContext
    {
        $scope = $this->createCompositionScope($module);
        if (null === $scope) {
            return $context->toDead();
        }

        $moduleId = spl_object_id($module);
        if (isset($this->activeModules[$moduleId])) {
            $this->addDiagnostic($origin, DiagnosticCode::UnsupportedTemplateComposition, \sprintf('Recursive composition of the "%s" template is not supported.', $module->getTemplateName()));

            return $context->toDead();
        }

        $this->activeModules[$moduleId] = true;
        $this->compositionScopes[] = $scope;

        $last = \count($scope['modules']) - 1;
        foreach ($scope['modules'] as $index => $scopeModule) {
            $context = $this->analyzeNodeInModule($scopeModule->getNode('display_start'), $scopeModule, $context);
            $context = $this->analyzeNodeInModule($scopeModule->getNode('body'), $scopeModule, $context);
            if ($last === $index) {
                $context = $this->analyzeNodeInModule($scopeModule->getNode('display_end'), $scopeModule, $context);
            }
        }
        for ($index = $last - 1; 0 <= $index; --$index) {
            $scopeModule = $scope['modules'][$index];
            $context = $this->analyzeNodeInModule($scopeModule->getNode('display_end'), $scopeModule, $context);
        }

        array_pop($this->compositionScopes);
        unset($this->activeModules[$moduleId]);

        return $context;
    }

    private function analyzeNodeInModule(Node $node, ModuleNode $module, HtmlContext $context): HtmlContext
    {
        $this->moduleStack[] = $module;
        $context = $this->analyzeNode($node, $context);
        array_pop($this->moduleStack);

        return $context;
    }

    /**
     * @return array{
     *     modules: list<ModuleNode>,
     *     blocks: array<string, list<array{module: ModuleNode, node: BlockNode}>>,
     *     imports: array<string, ModuleNode>
     * }|null
     */
    private function createCompositionScope(ModuleNode $module): ?array
    {
        $modules = [];
        $seen = [];
        $current = $module;

        while (true) {
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                $this->addDiagnostic($module, DiagnosticCode::UnsupportedTemplateComposition, 'Recursive template inheritance is not supported.');

                return null;
            }
            $seen[$id] = true;
            $modules[] = $current;
            $this->registerEmbeddedModules($current);
            $this->collectIndependentDiagnostics($current->getNode('display_start'));
            $this->collectModuleIndependentDiagnostics($current);
            $this->collectIndependentDiagnostics($current->getNode('display_end'));

            if (!$current->hasNode('parent')) {
                break;
            }

            $parent = $this->resolveTemplateExpression($current->getNode('parent'), $current);
            if (null === $parent) {
                return null;
            }
            $current = $parent;
        }

        $blocks = [];
        $traitModules = [];
        foreach ($modules as $scopeModule) {
            $moduleBlocks = [];
            foreach ($scopeModule->getNode('traits') as $trait) {
                $traitModule = $this->resolveTemplateExpression($trait->getNode('template'), $scopeModule);
                if (null === $traitModule) {
                    return null;
                }
                $traitModules[] = $traitModule;
                $this->collectModuleIndependentDiagnostics($traitModule);
                foreach ($traitModule->getNode('blocks') as $name => $definition) {
                    $block = $this->findBlockNode($definition);
                    if (null === $block) {
                        continue;
                    }
                    $target = $trait->getNode('targets')->hasNode((string) $name) ? $trait->getNode('targets')->getNode((string) $name)->getAttribute('value') : $name;
                    $moduleBlocks[(string) $target] = ['module' => $traitModule, 'node' => $block];
                }
            }
            foreach ($scopeModule->getNode('blocks') as $name => $definition) {
                $block = $this->findBlockNode($definition);
                if (null !== $block) {
                    $moduleBlocks[(string) $name] = ['module' => $scopeModule, 'node' => $block];
                }
            }
            foreach ($moduleBlocks as $name => $definition) {
                $blocks[$name][] = $definition;
            }
        }

        $imports = [];
        $visitedImports = [];
        foreach ([...$modules, ...$traitModules] as $scopeModule) {
            $this->collectImports($scopeModule, $scopeModule->getNode('body'), $imports, $visitedImports);
            foreach (['blocks', 'macros'] as $name) {
                $this->collectImports($scopeModule, $scopeModule->getNode($name), $imports, $visitedImports);
            }
        }

        return ['modules' => $modules, 'blocks' => $blocks, 'imports' => $imports];
    }

    private function resolveTemplateExpression(Node $expression, Node $origin, bool $ignoreMissing = false): ?ModuleNode
    {
        if ($expression instanceof ConstantExpression && \is_string($expression->getAttribute('value'))) {
            $name = $expression->getAttribute('value');
            $module = $this->templateResolver?->resolve($name, $origin->getTemplateName());
            if (null !== $module || $ignoreMissing) {
                return $module;
            }

            $this->addDiagnostic($origin, DiagnosticCode::UnsupportedTemplateComposition, \sprintf('The statically referenced "%s" template cannot be resolved.', $name));

            return null;
        }

        if ($expression instanceof ArrayExpression) {
            for ($i = 1; $i < \count($expression); $i += 2) {
                if (!$expression->hasNode($i)) {
                    continue;
                }
                $candidate = $expression->getNode($i);
                if (!$candidate instanceof ConstantExpression || !\is_string($candidate->getAttribute('value'))) {
                    break;
                }
                if (null !== $module = $this->templateResolver?->resolve($candidate->getAttribute('value'), $origin->getTemplateName())) {
                    return $module;
                }
            }
            if ($ignoreMissing) {
                return null;
            }
        }

        $this->addDiagnostic($origin, DiagnosticCode::UnsupportedTemplateComposition, 'Dynamic template references are not supported by experimental contextual escaping analysis.');

        return null;
    }

    private function registerEmbeddedModules(ModuleNode $module): void
    {
        foreach ($module->getAttribute('embedded_templates') as $embeddedModule) {
            if (!$embeddedModule instanceof ModuleNode) {
                continue;
            }
            $this->embeddedModules[$embeddedModule->getAttribute('index')] = $embeddedModule;
            $this->registerEmbeddedModules($embeddedModule);
        }
    }

    private function findBlockNode(Node $node): ?BlockNode
    {
        if ($node instanceof BlockNode) {
            return $node;
        }

        foreach ($node as $child) {
            if (null !== $block = $this->findBlockNode($child)) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @param array<string, ModuleNode> $imports
     * @param array<int, true>          $visited
     */
    private function collectImports(ModuleNode $module, Node $node, array &$imports, array &$visited): void
    {
        if ($node instanceof ImportNode) {
            $variable = $node->getNode('var')->getNode('var');
            $expression = $node->getNode('expr');
            $importedModule = $expression instanceof ContextVariable && '_self' === $expression->getAttribute('name') ? $module : $this->resolveTemplateExpression($expression, $node);
            if ($variable instanceof MacroVariable && null !== $importedModule) {
                $imports[$this->getMacroVariableKey($variable, $module)] = $importedModule;
                $id = spl_object_id($importedModule);
                if (!isset($visited[$id])) {
                    $visited[$id] = true;
                    $this->collectImports($importedModule, $importedModule->getNode('body'), $imports, $visited);
                    $this->collectImports($importedModule, $importedModule->getNode('macros'), $imports, $visited);
                }
            }

            return;
        }

        foreach ($node as $child) {
            $this->collectImports($module, $child, $imports, $visited);
        }
    }

    private function getMacroVariableKey(MacroVariable $variable, ModuleNode $module): string
    {
        $name = $variable->getAttribute('name');

        return \sprintf('%d:%s', spl_object_id($module), null === $name ? '@'.$variable->getTemplateLine() : $name);
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
            BlockReferenceNode::class => $this->analyzeBlock($node->getAttribute('name'), $context, $node),
            BlockReferenceExpression::class => $this->analyzeBlockExpression($node, $context),
            ParentExpression::class => $this->analyzeParentBlock($node, $context),
            IncludeNode::class => $this->analyzeInclude($node, $context),
            EmbedNode::class => $this->analyzeEmbed($node, $context),
            MacroReferenceExpression::class => $this->analyzeMacro($node, $context),
            BlockNode::class => $this->analyzeNode($node->getNode('body'), $context, $explicitAutoescape),
            ImportNode::class, MacroDeclarationNode::class => $context,
            CaptureNode::class => $this->rejectCompositionNode($node, $context),
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

    private function analyzeBlock(string $name, HtmlContext $context, Node $origin, int $index = 0): HtmlContext
    {
        if (!$this->compositionScopes) {
            return $this->rejectCompositionNode($origin, $context);
        }

        $scope = $this->compositionScopes[\count($this->compositionScopes) - 1];
        if (!isset($scope['blocks'][$name][$index])) {
            $this->addDiagnostic($origin, DiagnosticCode::UnsupportedTemplateComposition, \sprintf('The "%s" block cannot be resolved.', $name));

            return $context->toDead();
        }

        $definitions = $scope['blocks'][$name];
        $definition = $definitions[$index];
        $key = spl_object_id($definition['node']).':'.$index;
        if (isset($this->activeBlocks[$key])) {
            $this->addDiagnostic($origin, DiagnosticCode::UnsupportedTemplateComposition, \sprintf('Recursive composition of the "%s" block is not supported.', $name));

            return $context->toDead();
        }

        $this->activeBlocks[$key] = true;
        $this->blockStack[] = ['name' => $name, 'definitions' => $definitions, 'index' => $index];
        $this->moduleStack[] = $definition['module'];
        $context = $this->analyzeNode($definition['node']->getNode('body'), $context);
        array_pop($this->moduleStack);
        array_pop($this->blockStack);
        unset($this->activeBlocks[$key]);

        return $context;
    }

    private function analyzeBlockExpression(BlockReferenceExpression $node, HtmlContext $context): HtmlContext
    {
        if ($node->isDefinedTestEnabled()) {
            return $context;
        }
        $nameNode = $node->getNode('name');
        if (!$nameNode instanceof ConstantExpression || !\is_string($nameNode->getAttribute('value'))) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'Dynamic block names are not supported by experimental contextual escaping analysis.');

            return $context->toDead();
        }

        if (!$node->hasNode('template')) {
            return $this->analyzeBlock($nameNode->getAttribute('value'), $context, $node);
        }

        $module = $this->resolveTemplateExpression($node->getNode('template'), $node);
        if (null === $module || null === $scope = $this->createCompositionScope($module)) {
            return $context->toDead();
        }

        $this->compositionScopes[] = $scope;
        $context = $this->analyzeBlock($nameNode->getAttribute('value'), $context, $node);
        array_pop($this->compositionScopes);

        return $context;
    }

    private function analyzeParentBlock(ParentExpression $node, HtmlContext $context): HtmlContext
    {
        if (!$this->blockStack) {
            return $this->rejectCompositionNode($node, $context);
        }

        $frame = $this->blockStack[\count($this->blockStack) - 1];

        return $this->analyzeBlock($frame['name'], $context, $node, 1 + $frame['index']);
    }

    private function analyzeInclude(IncludeNode $node, HtmlContext $context): HtmlContext
    {
        $module = $this->resolveTemplateExpression($node->getNode('expr'), $node, $node->getAttribute('ignore_missing'));
        if (null === $module) {
            return $node->getAttribute('ignore_missing') ? $context : $context->toDead();
        }

        return $this->analyzeModule($module, $context, $node);
    }

    private function analyzeEmbed(EmbedNode $node, HtmlContext $context): HtmlContext
    {
        $index = $node->getAttribute('index');
        if (!isset($this->embeddedModules[$index])) {
            return $this->rejectCompositionNode($node, $context);
        }

        return $this->analyzeModule($this->embeddedModules[$index], $context, $node);
    }

    private function analyzeMacro(MacroReferenceExpression $node, HtmlContext $context): HtmlContext
    {
        if ($node->isDefinedTestEnabled()) {
            return $context;
        }
        if (!$this->compositionScopes || !$this->moduleStack) {
            return $this->rejectCompositionNode($node, $context);
        }

        $template = $node->getNode('template');
        if (!$template instanceof MacroVariable) {
            return $this->rejectCompositionNode($node, $context);
        }

        $scope = $this->compositionScopes[\count($this->compositionScopes) - 1];
        $currentModule = $this->moduleStack[\count($this->moduleStack) - 1];
        $module = '_self' === $template->getAttribute('name') ? $currentModule : ($scope['imports'][$this->getMacroVariableKey($template, $currentModule)] ?? null);
        if (null === $module) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'The macro template cannot be resolved.');

            return $context->toDead();
        }

        $name = $node->getAttribute('name');
        if (!\is_string($name) || null === $macro = $this->findMacroNode($module->getNode('macros'), $name)) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'Dynamic or unknown macro names are not supported by experimental contextual escaping analysis.');

            return $context->toDead();
        }

        $key = spl_object_id($module).':'.$name;
        if (isset($this->activeMacros[$key])) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, \sprintf('Recursive composition of the "%s" macro is not supported.', $name));

            return $context->toDead();
        }

        $macroScope = $this->createCompositionScope($module);
        if (null === $macroScope) {
            return $context->toDead();
        }

        $this->activeMacros[$key] = true;
        $this->compositionScopes[] = $macroScope;
        $this->moduleStack[] = $module;
        $context = $this->analyzeNode($macro->getNode('body'), $context);
        array_pop($this->moduleStack);
        array_pop($this->compositionScopes);
        unset($this->activeMacros[$key]);

        return $context;
    }

    private function findMacroNode(Node $node, string $name): ?MacroNode
    {
        if ($node instanceof MacroNode && $name === $node->getAttribute('name')) {
            return $node;
        }

        foreach ($node as $child) {
            if (null !== $macro = $this->findMacroNode($child, $name)) {
                return $macro;
            }
        }

        return null;
    }

    private function analyzePrint(PrintNode $node, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        /** @var AbstractExpression $expression */
        $expression = $node->getNode('expr');

        if ($this->isDirectCompositionExpression($expression)) {
            return $this->analyzeCompositionExpression($expression, $context, $node);
        }

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

    private function isDirectCompositionExpression(AbstractExpression $expression): bool
    {
        if ($expression instanceof SupportDefinedTestInterface && $expression->isDefinedTestEnabled()) {
            return false;
        }

        return $expression instanceof BlockReferenceExpression
            || $expression instanceof MacroReferenceExpression
            || $expression instanceof ParentExpression
            || ($expression instanceof FunctionExpression && 'include' === $expression->getAttribute('twig_callable')->getName());
    }

    private function analyzeCompositionExpression(AbstractExpression $expression, HtmlContext $context, PrintNode $origin): HtmlContext
    {
        if ($expression instanceof BlockReferenceExpression) {
            return $this->analyzeBlockExpression($expression, $context);
        }
        if ($expression instanceof MacroReferenceExpression) {
            return $this->analyzeMacro($expression, $context);
        }
        if ($expression instanceof ParentExpression) {
            return $this->analyzeParentBlock($expression, $context);
        }
        if ($expression instanceof FunctionExpression && 'include' === $expression->getAttribute('twig_callable')->getName()) {
            $arguments = $expression->getNode('arguments');
            if (!$arguments->hasNode(0)) {
                return $this->rejectCompositionNode($origin, $context);
            }
            $ignoreMissing = $arguments->hasNode(3) && $arguments->getNode(3) instanceof ConstantExpression && true === $arguments->getNode(3)->getAttribute('value');
            $module = $this->resolveTemplateExpression($arguments->getNode(0), $origin, $ignoreMissing);
            if (null === $module) {
                return $ignoreMissing ? $context : $context->toDead();
            }

            return $this->analyzeModule($module, $context, $origin);
        }

        return $context;
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
            if (!\in_array($node->getAttribute('twig_callable')->getName(), ['e', 'escape'], true)) {
                return [];
            }
            if ($this->isAutomaticEscape($node)) {
                return $this->findExplicitEscapingStrategies($node->getNode('node'));
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

        return $arguments->hasNode(2) && $arguments->getNode(2) instanceof ConstantExpression && true === $arguments->getNode(2)->getAttribute('value');
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
                if (!$this->isDirectCompositionExpression($expression) && $this->containsUnsupportedComposition($expression)) {
                    $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'Nested template, block, parent block, and macro results are not supported by experimental contextual escaping analysis.');
                }

                return;

            case IfNode::class:
                $tests = $node->getNode('tests');
                for ($i = 0; $i < \count($tests); $i += 2) {
                    if ($tests->hasNode((string) (1 + $i))) {
                        $this->collectIndependentDiagnostics($tests->getNode((string) (1 + $i)));
                    }
                }
                if ($node->hasNode('else')) {
                    $this->collectIndependentDiagnostics($node->getNode('else'));
                }

                return;

            case ForNode::class:
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
            case DeprecatedNode::class:
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
            case BlockReferenceNode::class:
            case BlockReferenceExpression::class:
            case MacroReferenceExpression::class:
            case ParentExpression::class:
            case ImportNode::class:
            case IncludeNode::class:
            case EmbedNode::class:
            case BlockNode::class:
            case MacroDeclarationNode::class:
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
