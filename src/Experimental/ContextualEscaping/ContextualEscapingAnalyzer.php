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
use Twig\Node\Expression\Variable\LocalVariable;
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
use Twig\TwigFilter;
use Twig\TwigFunction;

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

    /** @var array<string, HtmlContext> */
    private array $activeMacros = [];

    /** @var array<string, ContentTypeSet> */
    private array $contentTypes = [];

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
        $this->contentTypes = [];

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

        $context = match ($node::class) {
            BodyNode::class, Nodes::class => $this->analyzeSequence($node, $context, $explicitAutoescape),
            EmptyNode::class => $context,
            TextNode::class => $this->contextParser->consume($context, $node->getAttribute('data')),
            PrintNode::class => $this->analyzePrint($node, $context, $explicitAutoescape),
            IfNode::class => $this->analyzeIf($node, $context, $explicitAutoescape),
            ForNode::class => $this->analyzeFor($node, $context, $explicitAutoescape),
            ForElseNode::class => $this->analyzeNode($node->getNode('body'), $context, $explicitAutoescape),
            WithNode::class => $this->analyzeWith($node, $context, $explicitAutoescape),
            AutoEscapeNode::class => $this->analyzeAutoEscape($node, $context),
            SetNode::class => $this->analyzeSet($node, $context, $explicitAutoescape),
            BlockReferenceNode::class => $this->analyzeBlock($node->getAttribute('name'), $context, $node),
            BlockReferenceExpression::class => $this->analyzeBlockExpression($node, $context),
            ParentExpression::class => $this->analyzeParentBlock($node, $context),
            IncludeNode::class => $this->analyzeInclude($node, $context),
            EmbedNode::class => $this->analyzeEmbed($node, $context),
            MacroReferenceExpression::class => $this->analyzeMacro($node, $context),
            BlockNode::class => $this->analyzeNode($node->getNode('body'), $context, $explicitAutoescape),
            ImportNode::class, MacroDeclarationNode::class => $context,
            CaptureNode::class => $this->analyzeNode($node->getNode('body'), $context, $explicitAutoescape),
            CheckSecurityCallNode::class, CheckSecurityNode::class, ConfigNode::class, DeprecatedNode::class, DoNode::class, FlushNode::class, TypesNode::class => $context,
            default => $this->rejectUnknownNode($node, $context),
        };

        if ($context->hasMetaRefreshConflict()) {
            $this->addDiagnostic($node, DiagnosticCode::AmbiguousMetaRefreshContext, 'The meta refresh discriminator appears after dynamic content, so the required escaping cannot be determined safely.');

            return $context->toDead();
        }

        return $context;
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
            return $context;
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
        $contentTypes = $this->contentTypes;
        $context = $this->analyzeNode($definition['node']->getNode('body'), $context);
        $this->contentTypes = $contentTypes;
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

        $contentTypes = $this->contentTypes;
        if ($node->hasNode('variables')) {
            $this->applyVariableContentTypes($node->getNode('variables'), $node->getAttribute('only'));
        } elseif ($node->getAttribute('only')) {
            $this->contentTypes = [];
        }
        $context = $this->analyzeModule($module, $context, $node);
        $this->contentTypes = $contentTypes;

        return $context;
    }

    private function analyzeEmbed(EmbedNode $node, HtmlContext $context): HtmlContext
    {
        $index = $node->getAttribute('index');
        if (!isset($this->embeddedModules[$index])) {
            return $this->rejectCompositionNode($node, $context);
        }

        $contentTypes = $this->contentTypes;
        if ($node->hasNode('variables')) {
            $this->applyVariableContentTypes($node->getNode('variables'), $node->getAttribute('only'));
        } elseif ($node->getAttribute('only')) {
            $this->contentTypes = [];
        }
        $context = $this->analyzeModule($this->embeddedModules[$index], $context, $node);
        $this->contentTypes = $contentTypes;

        return $context;
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
            $input = $this->activeMacros[$key]->nudgeAttributeValue();
            if ($input->equals($context->nudgeAttributeValue())) {
                return $context;
            }
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, \sprintf('Recursive composition of the "%s" macro enters an incompatible context.', $name));

            return $context->toDead();
        }

        $macroScope = $this->createCompositionScope($module);
        if (null === $macroScope) {
            return $context->toDead();
        }

        $this->activeMacros[$key] = $context;
        $this->compositionScopes[] = $macroScope;
        $this->moduleStack[] = $module;
        $contentTypes = $this->contentTypes;
        $this->contentTypes = $this->getMacroArgumentContentTypes($node, $macro);
        $context = $this->analyzeNode($macro->getNode('body'), $context);
        $this->contentTypes = $contentTypes;
        array_pop($this->moduleStack);
        array_pop($this->compositionScopes);
        unset($this->activeMacros[$key]);

        return $context;
    }

    /**
     * @return array<string, ContentTypeSet>
     */
    private function getMacroArgumentContentTypes(MacroReferenceExpression $reference, MacroNode $macro): array
    {
        $macroArguments = $macro->getNode('arguments');
        $referenceArguments = $reference->getNode('arguments');
        if (!$macroArguments instanceof ArrayExpression || !$referenceArguments instanceof ArrayExpression) {
            return [];
        }

        $parameters = [];
        foreach ($macroArguments->getKeyValuePairs() as $pair) {
            $name = $pair['key']->getAttribute('name');
            if (\is_string($name)) {
                $parameters[] = $name;
            }
        }

        $contentTypes = [];
        foreach ($referenceArguments->getKeyValuePairs() as $index => $pair) {
            $name = $pair['key']->getAttribute('name');
            $name = \is_string($name) ? $name : ($parameters[$index] ?? null);
            if (null === $name || !$pair['value'] instanceof AbstractExpression) {
                continue;
            }
            $valueContentTypes = $this->inferContentTypes($pair['value'], new HtmlContext());
            if (!$valueContentTypes->isPlainText()) {
                $contentTypes['context:'.$name] = $valueContentTypes;
            }
        }

        return $contentTypes;
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

        $context = $context->nudgeAttributeValue()->resolveJavaScriptPendingTokenForInterpolation()->resolveCssPendingTokenForInterpolation();
        $contentTypes = $this->inferContentTypes($expression, $context);
        if (null !== $explicitAutoescape && false !== $explicitAutoescape && $contentTypes->isPlainText()) {
            $strategy = true === $explicitAutoescape ? 'html' : $explicitAutoescape;
            $contentTypes = $this->contentTypesForStrategies([$strategy]);
            if ($contentTypes->isPlainText()) {
                $this->addDiagnostic($node, DiagnosticCode::MismatchedExplicitEscaping, \sprintf('The explicit "%s" autoescaping strategy has no known contextual content type.', $strategy));
            }
        }

        $context = $context->recordAttributeInterpolation($contentTypes->contains(ContentType::TrustedInnermost));
        $plan = $this->inferPlan($node, $context, $contentTypes);
        if (null === $plan) {
            return $this->contextAfterUnsupportedPrint($context);
        }

        $this->result->addInferredEscape(new InferredEscape($node, $plan));
        $operations = $plan->getOperations();
        $context = $context
            ->afterUrlInterpolation($contentTypes->contains(ContentType::UrlComponent))
            ->afterCssUrlInterpolation($contentTypes->contains(ContentType::UrlComponent))
            ->afterCssInterpolation($this->cssInterpolationCanChangeContext($contentTypes, $operations))
            ->afterMetaRefreshUrlInterpolation($contentTypes->contains(ContentType::UrlComponent))
            ->afterMetaRefreshInterpolation(
                \in_array(EscapeOperation::MetaRefreshDelay, $operations, true),
                $contentTypes->contains(ContentType::TrustedInnermost),
            )
            ->afterSrcsetInterpolation(
                $contentTypes->contains(ContentType::TrustedInnermost),
                $contentTypes->contains(ContentType::Srcset),
                $contentTypes->contains(ContentType::Url),
                $contentTypes->contains(ContentType::UrlComponent),
            );

        return $context->afterJavaScriptInterpolation(\in_array(EscapeOperation::JavaScriptValue, $operations, true));
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

    private function analyzeCompositionExpression(AbstractExpression $expression, HtmlContext $context, Node $origin): HtmlContext
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
            $template = $arguments->hasNode(0) ? $arguments->getNode(0) : ($arguments->hasNode('template') ? $arguments->getNode('template') : null);
            if (null === $template) {
                return $this->rejectCompositionNode($origin, $context);
            }
            $ignoreMissingNode = $arguments->hasNode(3) ? $arguments->getNode(3) : ($arguments->hasNode('ignore_missing') ? $arguments->getNode('ignore_missing') : null);
            $ignoreMissing = $ignoreMissingNode instanceof ConstantExpression && true === $ignoreMissingNode->getAttribute('value');
            $module = $this->resolveTemplateExpression($template, $origin, $ignoreMissing);
            if (null === $module) {
                return $ignoreMissing ? $context : $context->toDead();
            }

            $contentTypes = $this->contentTypes;
            $withContextNode = $arguments->hasNode(2) ? $arguments->getNode(2) : ($arguments->hasNode('with_context') ? $arguments->getNode('with_context') : null);
            $withContext = !($withContextNode instanceof ConstantExpression) || false !== $withContextNode->getAttribute('value');
            if ($arguments->hasNode(1)) {
                $this->applyVariableContentTypes($arguments->getNode(1), !$withContext);
            } elseif ($arguments->hasNode('variables')) {
                $this->applyVariableContentTypes($arguments->getNode('variables'), !$withContext);
            } elseif (!$withContext) {
                $this->contentTypes = [];
            }
            $context = $this->analyzeModule($module, $context, $origin);
            $this->contentTypes = $contentTypes;

            return $context;
        }

        return $context;
    }

    private function inferContentTypes(AbstractExpression $expression, HtmlContext $context): ContentTypeSet
    {
        if ($expression instanceof ContextVariable || $expression instanceof LocalVariable) {
            return $this->contentTypes[$this->getVariableKey($expression)] ?? new ContentTypeSet([ContentType::PlainText]);
        }

        if ($this->isDirectCompositionExpression($expression)) {
            $output = $this->analyzeCompositionExpression($expression, new HtmlContext(), $expression);
            if (HtmlState::Dead === $output->getState()) {
                return new ContentTypeSet([ContentType::PlainText]);
            }
            if (HtmlState::Text !== $output->getState()) {
                $this->addDiagnostic($expression, DiagnosticCode::IncompleteStructuredOutput, \sprintf('The structured output ends in %s instead of HTML text.', $output->describe()));

                return new ContentTypeSet([ContentType::PlainText]);
            }

            return new ContentTypeSet([ContentType::Html]);
        }

        if ($expression instanceof FilterExpression) {
            /** @var AbstractExpression $input */
            $input = $expression->getNode('node');
            $inputTypes = $this->inferContentTypes($input, $context);
            $filter = $expression->getAttribute('twig_callable');
            if (!$filter instanceof TwigFilter) {
                return new ContentTypeSet([ContentType::PlainText]);
            }
            if ($this->isAutomaticEscape($expression)) {
                return $inputTypes;
            }
            if ('raw' === $filter->getName()) {
                return new ContentTypeSet([ContentType::TrustedInnermost]);
            }
            if (\in_array($filter->getName(), ['e', 'escape'], true)) {
                $arguments = $expression->getNode('arguments');
                if (!\count($arguments)) {
                    return new ContentTypeSet([ContentType::Html]);
                }
                $strategy = $arguments->getNode(0);
                if (!$strategy instanceof ConstantExpression || !\is_string($strategy->getAttribute('value'))) {
                    $this->addDiagnostic($expression, DiagnosticCode::MismatchedExplicitEscaping, 'A dynamic escaping strategy has no contextual content type.');

                    return new ContentTypeSet([ContentType::PlainText]);
                }

                $contentTypes = $this->contentTypesForStrategies([$strategy->getAttribute('value')]);
                if ($contentTypes->isPlainText()) {
                    $this->addDiagnostic($expression, DiagnosticCode::MismatchedExplicitEscaping, \sprintf('The explicit "%s" escaping strategy has no contextual content type.', $strategy->getAttribute('value')));
                }

                return $contentTypes;
            }

            $safeTypes = $this->contentTypesForStrategies($filter->getSafe($expression->getNode('arguments')), false);
            if (!$safeTypes->isPlainText()) {
                return $safeTypes;
            }
            $preserved = $filter->getPreservesSafety();
            if (\in_array('all', $preserved, true)) {
                return $inputTypes;
            }

            return $inputTypes->intersect($this->contentTypesForStrategies($preserved, false));
        }

        if ($expression instanceof FunctionExpression) {
            $function = $expression->getAttribute('twig_callable');

            return $function instanceof TwigFunction ? $this->contentTypesForStrategies($function->getSafe($expression->getNode('arguments')), false) : new ContentTypeSet([ContentType::PlainText]);
        }

        if ($expression instanceof OperatorEscapeInterface) {
            $contentTypes = null;
            foreach ($expression->getOperandNamesToEscape() as $name) {
                /** @var AbstractExpression $operand */
                $operand = $expression->getNode($name);
                $operandTypes = $this->inferContentTypes($operand, $context);
                $contentTypes = null === $contentTypes ? $operandTypes : $contentTypes->intersect($operandTypes);
            }

            return $contentTypes ?? new ContentTypeSet([ContentType::PlainText]);
        }

        return new ContentTypeSet([ContentType::PlainText]);
    }

    /**
     * @param list<string> $strategies
     */
    private function contentTypesForStrategies(array $strategies, bool $escaped = true): ContentTypeSet
    {
        $types = [];
        foreach ($strategies as $strategy) {
            $type = match ($strategy) {
                'html' => ContentType::Html,
                'html_attr', 'html_attr_relaxed' => ContentType::HtmlAttribute,
                'js' => $escaped ? ContentType::JavaScriptString : ContentType::JavaScriptExpression,
                'js_string' => ContentType::JavaScriptString,
                'js_template' => ContentType::JavaScriptTemplateString,
                'js_regexp' => ContentType::JavaScriptRegExp,
                'css' => $escaped ? ContentType::CssString : ContentType::Css,
                'css_string' => ContentType::CssString,
                'url' => $escaped ? ContentType::UrlComponent : ContentType::Url,
                'srcset' => ContentType::Srcset,
                default => null,
            };
            if (null !== $type && !\in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return new ContentTypeSet($types ?: [ContentType::PlainText]);
    }

    private function inferPlan(PrintNode $node, HtmlContext $context, ContentTypeSet $contentTypes): ?EscapePlan
    {
        if ($context->getState()->isScriptData()) {
            return $this->inferJavaScriptPlan($node, $context, $contentTypes, false);
        }
        if (HtmlState::RawText === $context->getState() && null !== $context->getCssContext()) {
            return $this->inferCssPlan($node, $context, $contentTypes, false);
        }

        return match ($context->getState()) {
            HtmlState::Text => new EscapePlan($contentTypes->contains(ContentType::Html) || $contentTypes->contains(ContentType::TrustedInnermost) ? [] : [EscapeOperation::HtmlText]),
            HtmlState::Rcdata => new EscapePlan($contentTypes->contains(ContentType::HtmlRcdata) || $contentTypes->contains(ContentType::TrustedInnermost) ? [] : [EscapeOperation::HtmlRcdata]),
            HtmlState::AttributeValueDoubleQuoted, HtmlState::AttributeValueSingleQuoted => $this->inferAttributePlan($node, $context, $contentTypes, false),
            HtmlState::AttributeValueUnquoted => $this->inferAttributePlan($node, $context, $contentTypes, true),
            HtmlState::Comment, HtmlState::CommentStart, HtmlState::CommentStartDash, HtmlState::CommentEndDash, HtmlState::CommentEnd, HtmlState::CommentEndBang => $this->rejectCommentInterpolation($node),
            HtmlState::RawText, HtmlState::Plaintext => $this->rejectOutputContext($node, $context),
            default => $this->rejectStructuralInterpolation($node, $context),
        };
    }

    private function inferAttributePlan(PrintNode $node, HtmlContext $context, ContentTypeSet $contentTypes, bool $unquoted): ?EscapePlan
    {
        if (HtmlAttributeType::JavaScript === $context->getAttributeType()) {
            return $this->inferJavaScriptPlan($node, $context, $contentTypes, true, $unquoted);
        }
        if (HtmlAttributeType::Style === $context->getAttributeType()) {
            return $this->inferCssPlan($node, $context, $contentTypes, true, $unquoted);
        }
        if (HtmlAttributeType::Url === $context->getAttributeType()) {
            return $this->inferUrlPlan($node, $context, $contentTypes, $unquoted);
        }
        if (HtmlAttributeType::MetaRefresh === $context->getAttributeType()) {
            return $this->inferMetaRefreshPlan($node, $context, $contentTypes, $unquoted);
        }
        if (HtmlAttributeType::Srcset === $context->getAttributeType()) {
            return $this->inferSrcsetPlan($node, $context, $contentTypes, $unquoted);
        }
        if (HtmlAttributeType::MetaContentUnknown === $context->getAttributeType()) {
            $this->addDiagnostic($node, DiagnosticCode::AmbiguousMetaRefreshContext, 'The "http-equiv" attribute is dynamic, so the meta content context cannot be determined safely.');

            return null;
        }

        $attributeContentType = $unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute;
        $trustedInnermost = $contentTypes->contains(ContentType::TrustedInnermost);
        $outerPlan = $contentTypes->contains($attributeContentType) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted)) ? [] : [$unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute];
        $requiredContentType = match ($context->getAttributeType()) {
            HtmlAttributeType::Html => ContentType::Html,
            HtmlAttributeType::UrlList, HtmlAttributeType::MetaContent, HtmlAttributeType::None, HtmlAttributeType::Plain => null,
        };
        if ($trustedInnermost && \in_array($context->getAttributeType(), [HtmlAttributeType::Plain, HtmlAttributeType::MetaContent], true)) {
            return new EscapePlan([]);
        }
        if (($trustedInnermost && HtmlAttributeType::Html === $context->getAttributeType()) || (null !== $requiredContentType && $contentTypes->contains($requiredContentType))) {
            return new EscapePlan($outerPlan);
        }

        $analysis = match ($context->getAttributeType()) {
            HtmlAttributeType::UrlList => 'URL list',
            HtmlAttributeType::Html => 'embedded HTML',
            HtmlAttributeType::MetaContent => null,
            HtmlAttributeType::None => 'unknown contextual',
            HtmlAttributeType::Plain => null,
        };
        if (null !== $analysis) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedAttributeContext, \sprintf('Output in the "%s" attribute requires %s analysis, which is not implemented yet.', $context->getAttributeName(), $analysis));

            return null;
        }

        return new EscapePlan($outerPlan);
    }

    private function inferUrlPlan(PrintNode $node, HtmlContext $context, ContentTypeSet $contentTypes, bool $unquoted): ?EscapePlan
    {
        if (\in_array($context->getUrlPart(), [UrlPart::None, UrlPart::Unknown], true)) {
            $this->addDiagnostic($node, DiagnosticCode::AmbiguousUrlContext, 'Output after a dynamic URL without a static query or fragment delimiter is ambiguous.');

            return null;
        }

        $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
        $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
        $outerPlan = $outerSafe ? [] : [$outerOperation];
        if ($contentTypes->contains(ContentType::TrustedInnermost) || $contentTypes->contains(ContentType::UrlComponent)) {
            return new EscapePlan($outerPlan);
        }
        if (UrlPart::Start === $context->getUrlPart() && $contentTypes->contains(ContentType::Url)) {
            return new EscapePlan($outerPlan);
        }

        $operations = match ($context->getUrlPart()) {
            UrlPart::Start => [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize],
            UrlPart::Path => [EscapeOperation::UrlPath],
            UrlPart::QueryOrFragment => [EscapeOperation::UrlQuery],
        };
        if (!$outerPlan) {
            $outerPlan = [$outerOperation];
        }

        return new EscapePlan([...$operations, ...$outerPlan]);
    }

    private function inferSrcsetPlan(PrintNode $node, HtmlContext $context, ContentTypeSet $contentTypes, bool $unquoted): ?EscapePlan
    {
        $srcsetContext = $context->getSrcsetContext();
        if (null === $srcsetContext) {
            return $this->rejectOutputContext($node, $context);
        }

        $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
        $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
        $outerPlan = $outerSafe ? [] : [$outerOperation];
        if ($contentTypes->contains(ContentType::TrustedInnermost)) {
            return new EscapePlan($outerPlan);
        }

        if (SrcsetState::BeforeUrl === $srcsetContext->getState()) {
            if ($contentTypes->contains(ContentType::Srcset) || $contentTypes->contains(ContentType::UrlComponent)) {
                return new EscapePlan($outerPlan);
            }
            if ($contentTypes->contains(ContentType::Url)) {
                return new EscapePlan([EscapeOperation::UrlNormalize, $outerOperation]);
            }

            return new EscapePlan([EscapeOperation::SrcsetFilter, $outerOperation]);
        }

        if (SrcsetState::Url === $srcsetContext->getState()) {
            if ($contentTypes->contains(ContentType::Srcset) || \in_array($srcsetContext->getUrlPart(), [UrlPart::None, UrlPart::Unknown], true)) {
                $this->addDiagnostic($node, DiagnosticCode::AmbiguousSrcsetContext, 'Output in an ambiguous srcset URL context is not supported.');

                return null;
            }
            if ($contentTypes->contains(ContentType::UrlComponent)) {
                return new EscapePlan($outerPlan);
            }

            $operations = match ($srcsetContext->getUrlPart()) {
                UrlPart::Start => [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize],
                UrlPart::Path => [EscapeOperation::UrlPath],
                UrlPart::QueryOrFragment => [EscapeOperation::UrlQuery],
            };
            $operations[] = $outerOperation;

            return new EscapePlan($operations);
        }

        $message = match ($srcsetContext->getState()) {
            SrcsetState::UrlComma => 'Output immediately after a comma in a srcset URL is ambiguous because the comma may be part of the URL or terminate the candidate.',
            SrcsetState::BeforeDescriptor, SrcsetState::Descriptor, SrcsetState::DescriptorParenthesized, SrcsetState::AfterDescriptor => 'Output expressions in srcset descriptors are not supported.',
            SrcsetState::Unknown => 'Output after dynamic or character-reference srcset content is ambiguous.',
        };
        $this->addDiagnostic($node, DiagnosticCode::AmbiguousSrcsetContext, $message);

        return null;
    }

    private function inferMetaRefreshPlan(PrintNode $node, HtmlContext $context, ContentTypeSet $contentTypes, bool $unquoted): ?EscapePlan
    {
        $metaRefreshContext = $context->getMetaRefreshContext();
        if (null === $metaRefreshContext) {
            return $this->rejectOutputContext($node, $context);
        }
        if (\in_array($metaRefreshContext->getState(), [MetaRefreshState::DelayWhitespace, MetaRefreshState::BeforeUrl, MetaRefreshState::UrlPrefix, MetaRefreshState::UrlPrefixWhitespace, MetaRefreshState::Unknown], true)) {
            $this->addDiagnostic($node, DiagnosticCode::AmbiguousMetaRefreshContext, 'Output in an ambiguous meta refresh delimiter, URL prefix, or character-reference context is not supported.');

            return null;
        }

        $trusted = $contentTypes->contains(ContentType::TrustedInnermost);
        if (MetaRefreshState::Delay === $metaRefreshContext->getState()) {
            $operations = $trusted ? [] : [EscapeOperation::MetaRefreshDelay];
        } elseif (MetaRefreshState::Done === $metaRefreshContext->getState()) {
            $operations = [];
        } elseif (\in_array($metaRefreshContext->getState(), [MetaRefreshState::UrlStart, MetaRefreshState::Url, MetaRefreshState::UrlDoubleQuoted, MetaRefreshState::UrlSingleQuoted], true)) {
            $urlPart = $metaRefreshContext->getUrlPart();
            if (\in_array($urlPart, [UrlPart::None, UrlPart::Unknown], true)) {
                $this->addDiagnostic($node, DiagnosticCode::AmbiguousUrlContext, 'Output after a dynamic meta refresh URL without a static query or fragment delimiter is ambiguous.');

                return null;
            }
            if ($trusted || $contentTypes->contains(ContentType::UrlComponent)) {
                $operations = [];
            } elseif (UrlPart::Start === $urlPart && $contentTypes->contains(ContentType::Url)) {
                $operations = [EscapeOperation::UrlNormalize];
            } else {
                $operations = match ($urlPart) {
                    UrlPart::Start => [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize],
                    UrlPart::Path => [EscapeOperation::UrlPath],
                    UrlPart::QueryOrFragment => [EscapeOperation::UrlQuery],
                };
            }
        } else {
            throw new \LogicException(\sprintf('Unexpected meta refresh state "%s".', $metaRefreshContext->getState()->name));
        }

        $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
        $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
        if ($operations || !$outerSafe) {
            $operations[] = $outerOperation;
        }

        return new EscapePlan($operations);
    }

    /**
     * @param list<EscapeOperation> $operations
     */
    private function cssInterpolationCanChangeContext(ContentTypeSet $contentTypes, array $operations): bool
    {
        if (!$contentTypes->contains(ContentType::TrustedInnermost) && !$contentTypes->contains(ContentType::Css)) {
            return false;
        }

        foreach ($operations as $operation) {
            if (\in_array($operation, [EscapeOperation::CssValue, EscapeOperation::CssString, EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::UrlPath, EscapeOperation::UrlQuery], true)) {
                return false;
            }
        }

        return true;
    }

    private function inferCssPlan(PrintNode $node, HtmlContext $context, ContentTypeSet $contentTypes, bool $attribute, bool $unquoted = false): ?EscapePlan
    {
        $cssContext = $context->getCssContext();
        if (null === $cssContext) {
            return $this->rejectOutputContext($node, $context);
        }
        if (null !== $cssContext->getEscapeDigits() || '' !== $cssContext->getToken() || \in_array($cssContext->getState(), [CssState::Slash, CssState::UrlAfterValue, CssState::Unknown], true)) {
            $this->addDiagnostic($node, DiagnosticCode::AmbiguousCssContext, 'Output in an ambiguous CSS token, escape, or URL context is not supported.');

            return null;
        }
        if (\in_array($cssContext->getState(), [CssState::Comment, CssState::CommentStar], true)) {
            $this->addDiagnostic($node, DiagnosticCode::CssCommentInterpolation, 'Output expressions inside CSS comments are not supported.');

            return null;
        }
        if (\in_array($cssContext->getState(), [CssState::Selector, CssState::Import, CssState::PropertyName], true)) {
            if (!$contentTypes->contains(ContentType::TrustedInnermost) && !$contentTypes->contains(ContentType::Css)) {
                $this->addDiagnostic($node, DiagnosticCode::UnsupportedOutputContext, \sprintf('Output expressions in CSS %s contexts are not supported.', match ($cssContext->getState()) {
                    CssState::Selector => 'selector',
                    CssState::Import => 'import',
                    CssState::PropertyName => 'property-name',
                }));

                return null;
            }

            $operation = null;
        } elseif (CssState::Value === $cssContext->getState()) {
            $operation = $contentTypes->contains(ContentType::TrustedInnermost) || $contentTypes->contains(ContentType::Css) ? null : EscapeOperation::CssValue;
        } elseif (\in_array($cssContext->getState(), [CssState::DoubleQuotedString, CssState::SingleQuotedString], true)) {
            $operation = $contentTypes->contains(ContentType::TrustedInnermost) || $contentTypes->contains(ContentType::CssString) ? null : EscapeOperation::CssString;
        } elseif (\in_array($cssContext->getState(), [CssState::UrlStart, CssState::UrlUnquoted, CssState::UrlDoubleQuoted, CssState::UrlSingleQuoted, CssState::ImportUrlDoubleQuoted, CssState::ImportUrlSingleQuoted], true)) {
            return $this->inferCssUrlPlan($node, $context, $contentTypes, $attribute, $unquoted);
        } else {
            throw new \LogicException(\sprintf('Unexpected CSS state "%s".', $cssContext->getState()->name));
        }

        $operations = null === $operation ? [] : [$operation];
        if ($attribute) {
            $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
            $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
            if (null !== $operation || !$outerSafe) {
                $operations[] = $outerOperation;
            }
        }

        return new EscapePlan($operations);
    }

    private function inferCssUrlPlan(PrintNode $node, HtmlContext $context, ContentTypeSet $contentTypes, bool $attribute, bool $unquoted): ?EscapePlan
    {
        $urlPart = $context->getCssContext()?->getUrlPart() ?? UrlPart::None;
        if (\in_array($urlPart, [UrlPart::None, UrlPart::Unknown], true)) {
            $this->addDiagnostic($node, DiagnosticCode::AmbiguousUrlContext, 'Output after a dynamic CSS URL without a static query or fragment delimiter is ambiguous.');

            return null;
        }

        if ($contentTypes->contains(ContentType::TrustedInnermost)) {
            $operations = [];
        } else {
            if ($contentTypes->contains(ContentType::UrlComponent)) {
                $operations = [];
            } elseif (UrlPart::Start === $urlPart && $contentTypes->contains(ContentType::Url)) {
                $operations = [EscapeOperation::UrlNormalize];
            } else {
                $operations = match ($urlPart) {
                    UrlPart::Start => [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize],
                    UrlPart::Path => [EscapeOperation::UrlPath],
                    UrlPart::QueryOrFragment => [EscapeOperation::UrlQuery],
                };
            }
            $operations[] = EscapeOperation::CssString;
        }

        if ($attribute) {
            $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
            $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
            if ($operations || !$outerSafe) {
                $operations[] = $outerOperation;
            }
        }

        return new EscapePlan($operations);
    }

    private function inferJavaScriptPlan(PrintNode $node, HtmlContext $context, ContentTypeSet $contentTypes, bool $attribute, bool $unquoted = false): ?EscapePlan
    {
        $javaScriptContext = $context->getJavaScriptContext();
        if (null === $javaScriptContext) {
            return $this->rejectOutputContext($node, $context);
        }
        if ($javaScriptContext->isEscaped() || $javaScriptContext->hasTemplateDollar() || (JavaScriptState::Code === $javaScriptContext->getState() && JavaScriptTokenType::None !== $javaScriptContext->getTokenType()) || \in_array($javaScriptContext->getState(), [JavaScriptState::Slash, JavaScriptState::LessThan, JavaScriptState::HtmlOpenCommentBang, JavaScriptState::HtmlOpenCommentDash, JavaScriptState::Minus, JavaScriptState::HtmlCloseCommentDashDash, JavaScriptState::Unknown], true)) {
            $this->addDiagnostic($node, DiagnosticCode::AmbiguousJavaScriptContext, 'Output in an ambiguous JavaScript token or slash context is not supported.');

            return null;
        }
        if (\in_array($javaScriptContext->getState(), [JavaScriptState::LineComment, JavaScriptState::BlockComment, JavaScriptState::BlockCommentStar], true)) {
            $this->addDiagnostic($node, DiagnosticCode::JavaScriptCommentInterpolation, 'Output expressions inside JavaScript comments are not supported.');

            return null;
        }

        $trusted = $contentTypes->contains(ContentType::TrustedInnermost);
        $operation = match ($javaScriptContext->getState()) {
            JavaScriptState::Code => $trusted || $contentTypes->contains(ContentType::JavaScriptExpression) ? null : EscapeOperation::JavaScriptValue,
            JavaScriptState::DoubleQuotedString, JavaScriptState::SingleQuotedString => $trusted || $contentTypes->contains(ContentType::JavaScriptString) ? null : EscapeOperation::JavaScriptString,
            JavaScriptState::TemplateString => $trusted || $contentTypes->contains(ContentType::JavaScriptTemplateString) ? null : EscapeOperation::JavaScriptTemplateString,
            JavaScriptState::RegExp => $trusted || $contentTypes->contains(ContentType::JavaScriptRegExp) ? null : EscapeOperation::JavaScriptRegExp,
        };
        $operations = null === $operation ? [] : [$operation];
        if ($attribute) {
            $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
            $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
            if (null !== $operation || !$outerSafe) {
                $operations[] = $outerOperation;
            }
        }

        return new EscapePlan($operations);
    }

    private function analyzeIf(IfNode $node, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        $branches = [];
        $contentTypeBranches = [];
        $inputContentTypes = $this->contentTypes;
        $tests = $node->getNode('tests');
        for ($i = 1; $i < \count($tests); $i += 2) {
            $this->contentTypes = $inputContentTypes;
            if ($tests->hasNode((string) $i)) {
                $branches[] = $this->analyzeNode($tests->getNode((string) $i), $context, $explicitAutoescape);
            } else {
                $branches[] = $context;
            }
            $contentTypeBranches[] = $this->contentTypes;
        }
        $this->contentTypes = $inputContentTypes;
        $branches[] = $node->hasNode('else') ? $this->analyzeNode($node->getNode('else'), $context, $explicitAutoescape) : $context;
        $contentTypeBranches[] = $this->contentTypes;
        $this->contentTypes = $this->joinContentTypeMaps($contentTypeBranches);

        return $this->joinContexts($branches, $node, 'The branches of this "if" tag end in incompatible contexts');
    }

    private function analyzeFor(ForNode $node, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        $inputContentTypes = $this->contentTypes;
        $bodyContext = $this->analyzeNode($node->getNode('body'), $context, $explicitAutoescape);
        $bodyContentTypes = $this->contentTypes;
        if (HtmlState::Dead === $bodyContext->getState()) {
            $this->contentTypes = $inputContentTypes;

            return $bodyContext;
        }

        $input = $context->nudgeAttributeValue();
        $output = $bodyContext->nudgeAttributeValue();
        if (!$input->equals($output)) {
            $this->addDiagnostic($node, DiagnosticCode::UnstableLoop, \sprintf('The "for" loop body changes the HTML context from %s to %s, so repeated iterations cannot be analyzed safely.', $input->describe(), $output->describe()));
            $this->contentTypes = $inputContentTypes;

            return $context->toDead();
        }

        if (!$node->hasNode('else')) {
            $this->contentTypes = $this->joinContentTypeMaps([$bodyContentTypes, $inputContentTypes]);

            return $output;
        }

        $this->contentTypes = $inputContentTypes;
        $elseContext = $this->analyzeNode($node->getNode('else'), $context, $explicitAutoescape);
        $this->contentTypes = $this->joinContentTypeMaps([$bodyContentTypes, $this->contentTypes]);

        return $this->joinContexts([$output, $elseContext], $node, 'The body and "else" branch of this "for" tag end in incompatible contexts');
    }

    private function analyzeWith(WithNode $node, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        $contentTypes = $this->contentTypes;
        if ($node->hasNode('variables')) {
            $this->applyVariableContentTypes($node->getNode('variables'), $node->getAttribute('only'));
        } elseif ($node->getAttribute('only')) {
            $this->contentTypes = [];
        }
        $context = $this->analyzeNode($node->getNode('body'), $context, $explicitAutoescape);
        $this->contentTypes = $contentTypes;

        return $context;
    }

    private function applyVariableContentTypes(Node $variables, bool $only = false): void
    {
        if (!$variables instanceof ArrayExpression) {
            if ($only) {
                $this->contentTypes = [];
            }

            return;
        }
        $inputContentTypes = $this->contentTypes;
        $outputContentTypes = $only ? [] : $inputContentTypes;
        for ($i = 0; $i < \count($variables); $i += 2) {
            $name = $variables->getNode($i);
            $value = $variables->getNode(1 + $i);
            if (!$name instanceof ConstantExpression || !\is_string($name->getAttribute('value')) || !$value instanceof AbstractExpression) {
                continue;
            }
            $key = 'context:'.$name->getAttribute('value');
            $this->contentTypes = $inputContentTypes;
            $valueContentTypes = $this->inferContentTypes($value, new HtmlContext());
            if ($valueContentTypes->isPlainText()) {
                unset($outputContentTypes[$key]);
            } else {
                $outputContentTypes[$key] = $valueContentTypes;
            }
        }
        $this->contentTypes = $outputContentTypes;
    }

    private function analyzeAutoEscape(AutoEscapeNode $node, HtmlContext $context): HtmlContext
    {
        return $this->analyzeNode($node->getNode('body'), $context, $node->getAttribute('value'));
    }

    private function analyzeSet(SetNode $node, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        $names = $node->getNode('names');
        $values = $node->getNode('values');
        if ($values instanceof CaptureNode) {
            $contentTypes = $this->analyzeCapturedContent($values, $explicitAutoescape);
            $this->assignContentTypes($names, [$contentTypes]);

            return $context;
        }
        if ($node->getAttribute('safe')) {
            $contentTypes = new ContentTypeSet([ContentType::Html]);
            if ($values instanceof ConstantExpression && \is_string($values->getAttribute('value'))) {
                $output = $this->contextParser->consume(new HtmlContext(), $values->getAttribute('value'));
                if (HtmlState::Text !== $output->getState()) {
                    $this->addDiagnostic($node, DiagnosticCode::IncompleteStructuredOutput, \sprintf('The captured output ends in %s instead of HTML text.', $output->describe()));
                    $contentTypes = new ContentTypeSet([ContentType::PlainText]);
                }
            }
            $this->assignContentTypes($names, [$contentTypes]);

            return $context;
        }

        $valueContentTypes = [];
        foreach ($values as $value) {
            $valueContentTypes[] = $value instanceof AbstractExpression ? $this->inferContentTypes($value, new HtmlContext()) : new ContentTypeSet([ContentType::PlainText]);
        }
        $this->assignContentTypes($names, $valueContentTypes);

        return $context;
    }

    private function analyzeCapturedContent(CaptureNode $capture, string|bool|null $explicitAutoescape): ContentTypeSet
    {
        $output = $this->analyzeNode($capture->getNode('body'), new HtmlContext(), $explicitAutoescape);
        if (HtmlState::Dead === $output->getState()) {
            return new ContentTypeSet([ContentType::PlainText]);
        }
        if (HtmlState::Text !== $output->getState()) {
            $this->addDiagnostic($capture, DiagnosticCode::IncompleteStructuredOutput, \sprintf('The captured output ends in %s instead of HTML text.', $output->describe()));

            return new ContentTypeSet([ContentType::PlainText]);
        }

        return new ContentTypeSet([ContentType::Html]);
    }

    /**
     * @param list<ContentTypeSet> $contentTypes
     */
    private function assignContentTypes(Node $names, array $contentTypes): void
    {
        if (!$names instanceof Nodes) {
            $names = new Nodes([$names]);
        }
        foreach ($names as $index => $name) {
            if (!$name instanceof ContextVariable && !$name instanceof LocalVariable) {
                continue;
            }
            $key = $this->getVariableKey($name);
            $type = $contentTypes[$index] ?? new ContentTypeSet([ContentType::PlainText]);
            if ($type->isPlainText()) {
                unset($this->contentTypes[$key]);
            } else {
                $this->contentTypes[$key] = $type;
            }
        }
    }

    private function getVariableKey(ContextVariable|LocalVariable $variable): string
    {
        return $variable instanceof LocalVariable ? 'local:'.spl_object_id($variable) : 'context:'.$variable->getAttribute('name');
    }

    /**
     * @param list<array<string, ContentTypeSet>> $maps
     *
     * @return array<string, ContentTypeSet>
     */
    private function joinContentTypeMaps(array $maps): array
    {
        $joined = $maps[0];
        foreach ($joined as $name => $contentTypes) {
            foreach (\array_slice($maps, 1) as $map) {
                $contentTypes = $contentTypes->intersect($map[$name] ?? new ContentTypeSet([ContentType::PlainText]));
            }
            if ($contentTypes->isPlainText()) {
                unset($joined[$name]);
            } else {
                $joined[$name] = $contentTypes;
            }
        }

        return $joined;
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
            if (null === $merged = $joined->merge($context)) {
                $this->addDiagnostic($node, DiagnosticCode::AmbiguousControlFlow, \sprintf('%s: %s and %s.', $message, $joined->describe(), $context->describe()));

                return $joined->toDead();
            }
            $joined = $merged;
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

    private function isAutomaticEscape(FilterExpression $node): bool
    {
        $arguments = $node->getNode('arguments');

        return $arguments->hasNode(2) && $arguments->getNode(2) instanceof ConstantExpression && true === $arguments->getNode(2)->getAttribute('value');
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
            case AutoEscapeNode::class:
                $this->collectIndependentDiagnostics($node->getNode('body'));

                return;

            case SetNode::class:
                $values = $node->getNode('values');
                if ($values instanceof CaptureNode && $values->hasNode('body')) {
                    $this->collectIndependentDiagnostics($values->getNode('body'));
                }

                return;

            case DoNode::class:
            case DeprecatedNode::class:
                return;

            case CaptureNode::class:
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
