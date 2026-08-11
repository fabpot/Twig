<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Analysis;

use Twig\Extra\ContextualEscaping\Context\CssState;
use Twig\Extra\ContextualEscaping\Context\HtmlContext;
use Twig\Extra\ContextualEscaping\Context\HtmlContextParser;
use Twig\Extra\ContextualEscaping\Context\HtmlState;
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
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\MacroReferenceExpression;
use Twig\Node\Expression\OperatorEscapeInterface;
use Twig\Node\Expression\ParentExpression;
use Twig\Node\Expression\SupportDefinedTestInterface;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\Expression\Variable\LocalVariable;
use Twig\Node\Expression\Variable\MacroVariable;
use Twig\Node\FlushNode;
use Twig\Node\ForElseNode;
use Twig\Node\ForLoopNode;
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
use Twig\Profiler\Node\EnterProfileNode;
use Twig\Profiler\Node\LeaveProfileNode;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @internal
 *
 * @experimental
 */
final class Analyzer
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

    /** @var array<string, FiniteStaticValueSet> */
    private array $staticValues = [];

    /** @var list<array{prior: array<string, int>, current: array<string, int>}> */
    private array $alternativeInferenceFrames = [];

    public function __construct(
        private HtmlContextParser $contextParser,
        private EscapePlanInferer $escapePlanInferer,
        private ?TemplateResolverInterface $templateResolver = null,
        private ?CurrentEscapingSafetyAnalyzer $currentSafetyAnalyzer = null,
        private ?NodeAnalyzerRegistry $nodeAnalyzerRegistry = null,
        private ?StaticExpressionAnalyzer $staticExpressionAnalyzer = null,
        private ?CallableAnalyzerRegistry $callableAnalyzerRegistry = null,
        private ?AttributeMapAnalyzerRegistry $attributeMapAnalyzerRegistry = null,
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
        $this->staticValues = [];
        $this->alternativeInferenceFrames = [];

        $context = $this->analyzeModule($module, new HtmlContext(), $module, str_ends_with($module->getTemplateName() ?? '', '.html.twig'));

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

    private function analyzeModule(ModuleNode $module, HtmlContext $context, Node $origin, bool $htmlParentAlternativesOnly = false): HtmlContext
    {
        $scopes = $this->createCompositionScopes($module, $htmlParentAlternativesOnly);
        if (null === $scopes) {
            return $context->toDead();
        }
        if ([] === $scopes) {
            return $context;
        }

        $moduleId = spl_object_id($module);
        if (isset($this->activeModules[$moduleId])) {
            $this->addDiagnostic($origin, DiagnosticCode::UnsupportedTemplateComposition, \sprintf('Recursive composition of the "%s" template is not supported.', $module->getTemplateName()));

            return $context->toDead();
        }

        $this->activeModules[$moduleId] = true;
        $initialContentTypes = $this->contentTypes;
        $initialStaticValues = $this->staticValues;
        $contexts = [];
        $contentTypeMaps = [];
        $staticValueMaps = [];
        $deduplicateAlternatives = 1 < \count($scopes);
        if ($deduplicateAlternatives) {
            $this->alternativeInferenceFrames[] = ['prior' => [], 'current' => []];
        }

        foreach ($scopes as $scope) {
            $this->contentTypes = $initialContentTypes;
            $this->staticValues = $initialStaticValues;
            $contexts[] = $this->analyzeCompositionScope($scope, $context);
            $contentTypeMaps[] = $this->contentTypes;
            $staticValueMaps[] = $this->staticValues;
            if ($deduplicateAlternatives) {
                $frame = \count($this->alternativeInferenceFrames) - 1;
                foreach ($this->alternativeInferenceFrames[$frame]['current'] as $key => $count) {
                    $this->alternativeInferenceFrames[$frame]['prior'][$key] = max($count, $this->alternativeInferenceFrames[$frame]['prior'][$key] ?? 0);
                }
                $this->alternativeInferenceFrames[$frame]['current'] = [];
            }
        }
        if ($deduplicateAlternatives) {
            array_pop($this->alternativeInferenceFrames);
        }

        unset($this->activeModules[$moduleId]);
        $this->contentTypes = $this->joinContentTypeMaps($contentTypeMaps);
        $this->staticValues = $this->joinStaticValueMaps($staticValueMaps);

        return $this->joinContexts($contexts, $origin, 'The static parent template alternatives end in incompatible contexts');
    }

    /**
     * @param array{
     *     modules: list<ModuleNode>,
     *     blocks: array<string, list<array{module: ModuleNode, node: BlockNode}>>,
     *     imports: array<string, ModuleNode>
     * } $scope
     */
    private function analyzeCompositionScope(array $scope, HtmlContext $context): HtmlContext
    {
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
     * @return list<array{
     *     modules: list<ModuleNode>,
     *     blocks: array<string, list<array{module: ModuleNode, node: BlockNode}>>,
     *     imports: array<string, ModuleNode>
     * }>|null
     */
    private function createCompositionScopes(ModuleNode $module, bool $htmlParentAlternativesOnly = false): ?array
    {
        $moduleChains = $this->collectInheritanceChains($module, [], [], $module, $htmlParentAlternativesOnly);
        if (null === $moduleChains) {
            return null;
        }

        $scopes = [];
        foreach ($moduleChains as $modules) {
            if (null === $scope = $this->createCompositionScopeFromModules($modules)) {
                return null;
            }
            $scopes[] = $scope;
        }

        return $scopes;
    }

    /**
     * @param list<ModuleNode> $modules
     * @param array<int, true> $seen
     *
     * @return list<list<ModuleNode>>|null
     */
    private function collectInheritanceChains(ModuleNode $current, array $modules, array $seen, ModuleNode $origin, bool $htmlParentAlternativesOnly): ?array
    {
        $id = spl_object_id($current);
        if (isset($seen[$id])) {
            $this->addDiagnostic($origin, DiagnosticCode::UnsupportedTemplateComposition, 'Recursive template inheritance is not supported.');

            return null;
        }
        $seen[$id] = true;
        $modules[] = $current;
        $this->registerEmbeddedModules($current);
        $this->collectIndependentDiagnostics($current->getNode('display_start'));
        $this->collectModuleIndependentDiagnostics($current);
        $this->collectIndependentDiagnostics($current->getNode('display_end'));

        if (!$current->hasNode('parent')) {
            return [$modules];
        }

        $parents = $this->resolveParentTemplateExpressions($current->getNode('parent'), $current, $htmlParentAlternativesOnly);
        if (null === $parents) {
            return null;
        }

        $chains = [];
        foreach ($parents as $parent) {
            $parentChains = $this->collectInheritanceChains($parent, $modules, $seen, $origin, $htmlParentAlternativesOnly);
            if (null === $parentChains) {
                return null;
            }
            array_push($chains, ...$parentChains);
        }

        return $chains;
    }

    /**
     * @param list<ModuleNode> $modules
     *
     * @return array{
     *     modules: list<ModuleNode>,
     *     blocks: array<string, list<array{module: ModuleNode, node: BlockNode}>>,
     *     imports: array<string, ModuleNode>
     * }|null
     */
    private function createCompositionScopeFromModules(array $modules): ?array
    {
        $blocks = [];
        $traitModules = [];
        foreach ($modules as $scopeModule) {
            if (null === $moduleBlocks = $this->collectModuleBlockDefinitions($scopeModule, $traitModules, [])) {
                return null;
            }
            foreach ($moduleBlocks as $name => $definitions) {
                $blocks[$name] ??= [];
                array_push($blocks[$name], ...$definitions);
            }
        }

        $imports = [];
        $visitedImports = [];
        foreach ([...$modules, ...$traitModules] as $scopeModule) {
            if (!$this->collectImports($scopeModule, $scopeModule->getNode('body'), $imports, $visitedImports)) {
                return null;
            }
            foreach (['blocks', 'macros'] as $name) {
                if (!$this->collectImports($scopeModule, $scopeModule->getNode($name), $imports, $visitedImports)) {
                    return null;
                }
            }
        }

        return ['modules' => $modules, 'blocks' => $blocks, 'imports' => $imports];
    }

    /**
     * @param list<ModuleNode> $traitModules
     * @param array<int, true> $seen
     *
     * @return array<string, list<array{module: ModuleNode, node: BlockNode}>>|null
     */
    private function collectModuleBlockDefinitions(ModuleNode $module, array &$traitModules, array $seen): ?array
    {
        $id = spl_object_id($module);
        if (isset($seen[$id])) {
            $this->addDiagnostic($module, DiagnosticCode::UnsupportedTemplateComposition, 'Recursive template traits are not supported.');

            return null;
        }
        $seen[$id] = true;

        $traitBlocks = [];
        foreach ($module->getNode('traits') as $trait) {
            $traitModule = $this->resolveTemplateExpression($trait->getNode('template'), $module);
            if (null === $traitModule) {
                return null;
            }
            $traitModules[] = $traitModule;
            $this->registerEmbeddedModules($traitModule);
            $this->collectModuleIndependentDiagnostics($traitModule);
            if (null === $definitions = $this->collectModuleBlockDefinitions($traitModule, $traitModules, $seen)) {
                return null;
            }
            foreach ($definitions as $name => $blockDefinitions) {
                $target = $trait->getNode('targets')->hasNode($name) ? $trait->getNode('targets')->getNode($name)->getAttribute('value') : $name;
                $traitBlocks[(string) $target] = $blockDefinitions;
            }
        }

        $blocks = $traitBlocks;
        foreach ($module->getNode('blocks') as $name => $definition) {
            $block = $this->findBlockNode($definition);
            if (null !== $block) {
                $blocks[(string) $name] = [
                    ['module' => $module, 'node' => $block],
                    ...($traitBlocks[(string) $name] ?? []),
                ];
            }
        }

        return $blocks;
    }

    /**
     * @return list<ModuleNode>|null
     */
    private function resolveParentTemplateExpressions(Node $expression, Node $origin, bool $htmlParentAlternativesOnly): ?array
    {
        $names = null;
        if ($expression instanceof ConstantExpression && \is_string($expression->getAttribute('value'))) {
            $names = [$expression->getAttribute('value')];
        } elseif ($expression instanceof AbstractExpression && null !== $values = $this->staticExpressionAnalyzer?->analyze($expression, [])) {
            $names = $values->getValues();
        }
        if (null === $names || [] === $names) {
            $this->addDiagnostic($origin, DiagnosticCode::UnsupportedTemplateComposition, 'Dynamic template references are not supported by experimental contextual escaping analysis.');

            return null;
        }

        $modules = [];
        $seen = [];
        foreach ($names as $name) {
            if (!\is_string($name)) {
                $this->addDiagnostic($origin, DiagnosticCode::UnsupportedTemplateComposition, 'Dynamic template references are not supported by experimental contextual escaping analysis.');

                return null;
            }
            if ($htmlParentAlternativesOnly && preg_match('/\.[^.\/]+\.twig$/D', $name) && !str_ends_with($name, '.html.twig')) {
                continue;
            }
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $module = $this->templateResolver?->resolve($name, $origin->getTemplateName());
            if (null === $module) {
                $this->addDiagnostic($origin, DiagnosticCode::UnsupportedTemplateComposition, \sprintf('The statically referenced "%s" template cannot be resolved.', $name));

                return null;
            }
            $modules[] = $module;
        }

        return $modules;
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
    private function collectImports(ModuleNode $module, Node $node, array &$imports, array &$visited): bool
    {
        if ($node instanceof ImportNode) {
            $variable = $node->getNode('var')->getNode('var');
            $expression = $node->getNode('expr');
            $importedModule = $expression instanceof ContextVariable && '_self' === $expression->getAttribute('name') ? $module : $this->resolveTemplateExpression($expression, $node);
            if ($this->isMacroVariable($variable) && null !== $importedModule) {
                $key = $this->getMacroVariableKey($variable, $module);
                if (isset($imports[$key]) && $imports[$key]->getTemplateName() !== $importedModule->getTemplateName()) {
                    $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'Reassigning a macro import to another template is not supported by experimental contextual escaping analysis.');

                    return false;
                }
                $imports[$key] = $importedModule;
                $id = spl_object_id($importedModule);
                if (!isset($visited[$id])) {
                    $visited[$id] = true;
                    if (!$this->collectImports($importedModule, $importedModule->getNode('body'), $imports, $visited) || !$this->collectImports($importedModule, $importedModule->getNode('macros'), $imports, $visited)) {
                        return false;
                    }
                }
            }

            return true;
        }

        foreach ($node as $child) {
            if (!$this->collectImports($module, $child, $imports, $visited)) {
                return false;
            }
        }

        return true;
    }

    private function getMacroVariableKey(Node $variable, ModuleNode $module): string
    {
        $name = $variable->getAttribute('name');

        return \sprintf('%d:%s', spl_object_id($module), null === $name ? '@'.$variable->getTemplateLine() : $name);
    }

    private function isMacroVariable(Node $node): bool
    {
        return $node instanceof MacroVariable || 'Twig\\Node\\Expression\\Variable\\TemplateVariable' === $node::class;
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
            ForLoopNode::class, EnterProfileNode::class, LeaveProfileNode::class => $context,
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
            default => $this->analyzeRegisteredNode($node, $context),
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
        $staticValues = $this->staticValues;
        $context = $this->analyzeNode($definition['node']->getNode('body'), $context);
        $this->contentTypes = $contentTypes;
        $this->staticValues = $staticValues;
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
        if (null === $module || null === $scopes = $this->createCompositionScopes($module)) {
            return $context->toDead();
        }

        $contexts = [];
        foreach ($scopes as $scope) {
            $this->compositionScopes[] = $scope;
            $contexts[] = $this->analyzeBlock($nameNode->getAttribute('value'), $context, $node);
            array_pop($this->compositionScopes);
        }

        return $this->joinContexts($contexts, $node, 'The static parent template alternatives render the block in incompatible contexts');
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
        $staticValues = $this->staticValues;
        if ($node->hasNode('variables')) {
            $this->applyVariableContentTypes($node->getNode('variables'), $node->getAttribute('only'));
        } elseif ($node->getAttribute('only')) {
            $this->contentTypes = [];
            $this->staticValues = [];
        }
        $context = $this->analyzeModule($module, $context, $node);
        $this->contentTypes = $contentTypes;
        $this->staticValues = $staticValues;

        return $context;
    }

    private function analyzeEmbed(EmbedNode $node, HtmlContext $context): HtmlContext
    {
        $index = $node->getAttribute('index');
        if (!isset($this->embeddedModules[$index])) {
            return $this->rejectCompositionNode($node, $context);
        }

        $contentTypes = $this->contentTypes;
        $staticValues = $this->staticValues;
        if ($node->hasNode('variables')) {
            $this->applyVariableContentTypes($node->getNode('variables'), $node->getAttribute('only'));
        } elseif ($node->getAttribute('only')) {
            $this->contentTypes = [];
            $this->staticValues = [];
        }
        $context = $this->analyzeModule($this->embeddedModules[$index], $context, $node);
        $this->contentTypes = $contentTypes;
        $this->staticValues = $staticValues;

        return $context;
    }

    private function analyzeMacro(MacroReferenceExpression $node, HtmlContext $context): HtmlContext
    {
        if ($node->isDefinedTestEnabled()) {
            return $context;
        }
        if (!$this->compositionScopes || !$this->moduleStack) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'The macro is used outside an analyzable template composition scope.');

            return $context->toDead();
        }

        $template = $node->getNode('template');
        if (!$this->isMacroVariable($template)) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, \sprintf('The macro template expression uses the unsupported "%s" node.', $template::class));

            return $context->toDead();
        }

        $scope = $this->compositionScopes[\count($this->compositionScopes) - 1];
        $currentModule = $this->moduleStack[\count($this->moduleStack) - 1];
        $module = '_self' === $template->getAttribute('name') ? $currentModule : ($scope['imports'][$this->getMacroVariableKey($template, $currentModule)] ?? null);
        if (null === $module) {
            $this->addDiagnostic($node, DiagnosticCode::UnsupportedTemplateComposition, 'The macro template cannot be resolved.');

            return $context->toDead();
        }

        $name = $node->getAttribute('name');
        if (\is_string($name) && str_starts_with($name, 'macro_')) {
            $name = substr($name, 6);
        }
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

        $scopes = \in_array($module, $scope['modules'], true) ? [$scope] : $this->createCompositionScopes($module);
        if (null === $scopes) {
            return $context->toDead();
        }

        $this->activeMacros[$key] = $context;
        $contentTypes = $this->contentTypes;
        $staticValues = $this->staticValues;
        $argumentContentTypes = $this->getMacroArgumentContentTypes($node, $macro);
        $argumentStaticValues = $this->getMacroArgumentStaticValues($node, $macro);
        $contexts = [];
        foreach ($scopes as $scope) {
            $this->compositionScopes[] = $scope;
            $this->moduleStack[] = $module;
            $this->contentTypes = $argumentContentTypes;
            $this->staticValues = $argumentStaticValues;
            $contexts[] = $this->analyzeNode($macro->getNode('body'), $context);
            array_pop($this->moduleStack);
            array_pop($this->compositionScopes);
        }
        $this->contentTypes = $contentTypes;
        $this->staticValues = $staticValues;
        unset($this->activeMacros[$key]);

        return $this->joinContexts($contexts, $node, 'The static parent template alternatives render the macro in incompatible contexts');
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

    /**
     * @return array<string, FiniteStaticValueSet>
     */
    private function getMacroArgumentStaticValues(MacroReferenceExpression $reference, MacroNode $macro): array
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

        $staticValues = [];
        foreach ($referenceArguments->getKeyValuePairs() as $index => $pair) {
            $name = $pair['key']->getAttribute('name');
            $name = \is_string($name) ? $name : ($parameters[$index] ?? null);
            if (null === $name || !$pair['value'] instanceof AbstractExpression || null === $value = $this->staticExpressionAnalyzer?->analyze($pair['value'], $this->staticValues)) {
                continue;
            }
            $staticValues['context:'.$name] = $value->withProvenance($name);
        }

        return $staticValues;
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

        if ($this->currentSafetyAnalyzer?->analyze($expression)['constant_output']) {
            $this->addInferredEscape(new InferredEscape($node, new EscapePlan([]), $context->describe()));

            return $this->analyzeConstantOutput($expression, $context, $node);
        }

        if ($expression instanceof ConstantExpression && !$expression->isDefinedTestEnabled() && \is_string($expression->getAttribute('value'))) {
            $this->addInferredEscape(new InferredEscape($node, new EscapePlan([]), $context->describe()));

            return $this->contextParser->consume($context, $expression->getAttribute('value'));
        }

        if (null !== $staticValues = $this->staticExpressionAnalyzer?->analyze($expression, $this->staticValues)) {
            $outputs = $this->renderStaticValues($staticValues);
            if (null !== $outputs) {
                $outputContext = $this->analyzeStaticOutputs($outputs, $context, $node);
                if (HtmlState::Dead !== $outputContext->getState()) {
                    $this->addInferredEscape(new InferredEscape($node, new EscapePlan([]), $context->describe(), $staticValues->getProvenance(), $outputs));
                }

                return $outputContext;
            }
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
        $inference = $this->escapePlanInferer->infer($context, $contentTypes);
        if (!$inference->isSuccessful()) {
            $this->addDiagnostic($node, $inference->getDiagnosticCode(), $inference->getDiagnosticMessage());

            return $this->contextAfterUnsupportedPrint($context);
        }
        $plan = $inference->getPlan();

        $this->addInferredEscape(new InferredEscape($node, $plan, $context->describe(), valueContracts: $this->collectValueContracts($expression)));
        $operations = $plan->getOperations();
        $context = $context
            ->afterUrlInterpolation($contentTypes->contains(ContentType::UrlComponent))
            ->afterCssUrlInterpolation($contentTypes->contains(ContentType::UrlComponent))
            ->afterCssInterpolation($this->escapePlanInferer->cssInterpolationCanChangeContext($contentTypes, $operations))
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

    /**
     * @return list<string>|null
     */
    private function renderStaticValues(FiniteStaticValueSet $staticValues): ?array
    {
        $outputs = [];
        foreach ($staticValues->getValues() as $value) {
            if (null === $output = StaticOutput::stringify($value)) {
                return null;
            }
            $outputs[$output] = true;
        }

        return array_keys($outputs);
    }

    /**
     * @param non-empty-list<string> $outputs
     */
    private function analyzeStaticOutputs(array $outputs, HtmlContext $context, PrintNode $origin): HtmlContext
    {
        $contexts = [];
        foreach ($outputs as $output) {
            $contexts[] = $this->contextParser->consume($context, $output);
        }

        $joined = $contexts[0];
        foreach (\array_slice($contexts, 1) as $outputContext) {
            if (null === $joined = $joined->merge($outputContext)) {
                return $this->staticOutputsPreserveContext($outputs, $context) ? $context : $this->joinContexts($contexts, $origin, 'The finite static outputs end in incompatible contexts');
            }
        }

        return $joined;
    }

    /**
     * @param non-empty-list<string> $outputs
     */
    private function staticOutputsPreserveContext(array $outputs, HtmlContext $context): bool
    {
        if (CssState::Value !== $context->getCssContext()?->getState()) {
            return false;
        }
        foreach ($outputs as $output) {
            if (!preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/iD', $output)) {
                return false;
            }
        }

        return true;
    }

    private function analyzeConstantOutput(AbstractExpression $expression, HtmlContext $context, PrintNode $origin): HtmlContext
    {
        if ($expression instanceof ConstantExpression) {
            $value = $expression->getAttribute('value');
            if (null === $output = StaticOutput::stringify($value)) {
                throw new \LogicException(\sprintf('Unsupported constant output of type "%s".', get_debug_type($value)));
            }

            return $this->contextParser->consume($context, $output);
        }

        if (!$expression instanceof OperatorEscapeInterface) {
            throw new \LogicException(\sprintf('The "%s" expression was incorrectly classified as constant output.', $expression::class));
        }

        $contexts = [];
        foreach ($expression->getOperandNamesToEscape() as $name) {
            $operand = $expression->getNode($name);
            if (!$operand instanceof AbstractExpression) {
                throw new \LogicException(\sprintf('The "%s" output operand is not an expression.', $expression::class));
            }
            $contexts[] = $this->analyzeConstantOutput($operand, $context, $origin);
        }

        return $this->joinContexts($contexts, $origin, 'The constant output branches end in incompatible contexts');
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
            $staticValues = $this->staticValues;
            $withContextNode = $arguments->hasNode(2) ? $arguments->getNode(2) : ($arguments->hasNode('with_context') ? $arguments->getNode('with_context') : null);
            $withContext = !($withContextNode instanceof ConstantExpression) || false !== $withContextNode->getAttribute('value');
            if ($arguments->hasNode(1)) {
                $this->applyVariableContentTypes($arguments->getNode(1), !$withContext);
            } elseif ($arguments->hasNode('variables')) {
                $this->applyVariableContentTypes($arguments->getNode('variables'), !$withContext);
            } elseif (!$withContext) {
                $this->contentTypes = [];
                $this->staticValues = [];
            }
            $context = $this->analyzeModule($module, $context, $origin);
            $this->contentTypes = $contentTypes;
            $this->staticValues = $staticValues;

            return $context;
        }

        return $context;
    }

    private function inferContentTypes(AbstractExpression $expression, HtmlContext $context): ContentTypeSet
    {
        if ($expression instanceof ContextVariable || $expression instanceof LocalVariable) {
            return $this->contentTypes[$this->getVariableKey($expression)] ?? new ContentTypeSet([ContentType::PlainText]);
        }

        if ($expression instanceof GetAttrExpression) {
            $input = $expression->getNode('node');
            $attribute = $expression->getNode('attribute');
            if ($input instanceof AbstractExpression && $attribute instanceof ConstantExpression && \is_string($name = $attribute->getAttribute('value'))) {
                $inputTypes = $this->inferContentTypes($input, $context);
                if ($inputTypes->contains(ContentType::HtmlAttributeList)) {
                    return match ($name) {
                        'defaults', 'only', 'without', 'add', 'remove', 'nested' => $inputTypes,
                        'render' => new ContentTypeSet([ContentType::HtmlAttribute]),
                        default => new ContentTypeSet([ContentType::PlainText]),
                    };
                }
            }

            return new ContentTypeSet([ContentType::PlainText]);
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
            if (null !== $analysis = $this->callableAnalyzerRegistry?->analyze($expression)) {
                return $analysis->getContentTypes();
            }
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
     * @return list<ValueContract>
     */
    private function collectValueContracts(Node $node): array
    {
        $contracts = [];
        if ($node instanceof FunctionExpression && null !== $analysis = $this->callableAnalyzerRegistry?->analyze($node)) {
            $contract = $analysis->getValueContract();
            $contracts[$this->getValueContractKey($contract)] = $contract;
        }
        foreach ($node as $child) {
            foreach ($this->collectValueContracts($child) as $contract) {
                $contracts[$this->getValueContractKey($contract)] = $contract;
            }
        }

        return array_values($contracts);
    }

    private function getValueContractKey(ValueContract $contract): string
    {
        return implode("\0", [
            $contract->getExpression(),
            $contract->getImplementation(),
            $contract->getContentType()->name,
            $contract->getSource(),
        ]);
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

    private function analyzeIf(IfNode $node, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        $branches = [];
        $contentTypeBranches = [];
        $staticValueBranches = [];
        $inputContentTypes = $this->contentTypes;
        $inputStaticValues = $this->staticValues;
        $tests = $node->getNode('tests');
        for ($i = 1; $i < \count($tests); $i += 2) {
            $this->contentTypes = $inputContentTypes;
            $this->staticValues = $inputStaticValues;
            if ($tests->hasNode((string) $i)) {
                $branches[] = $this->analyzeNode($tests->getNode((string) $i), $context, $explicitAutoescape);
            } else {
                $branches[] = $context;
            }
            $contentTypeBranches[] = $this->contentTypes;
            $staticValueBranches[] = $this->staticValues;
        }
        $this->contentTypes = $inputContentTypes;
        $this->staticValues = $inputStaticValues;
        $branches[] = $node->hasNode('else') ? $this->analyzeNode($node->getNode('else'), $context, $explicitAutoescape) : $context;
        $contentTypeBranches[] = $this->contentTypes;
        $staticValueBranches[] = $this->staticValues;
        $this->contentTypes = $this->joinContentTypeMaps($contentTypeBranches);
        $this->staticValues = $this->joinStaticValueMaps($staticValueBranches);

        return $this->joinContexts($branches, $node, 'The branches of this "if" tag end in incompatible contexts');
    }

    private function analyzeFor(ForNode $node, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        if (HtmlState::BeforeAttributeName === $context->getState() && null !== $this->attributeMapAnalyzerRegistry?->analyze($node, $this->getCurrentBlockName())) {
            return $context;
        }

        $inputContentTypes = $this->contentTypes;
        $inputStaticValues = $this->staticValues;
        $this->removeAssignedInferences($node->getNode('key_target'));
        $this->removeAssignedInferences($node->getNode('value_target'));
        $bodyContext = $this->analyzeNode($node->getNode('body'), $context, $explicitAutoescape);
        $bodyContentTypes = $this->contentTypes;
        $bodyStaticValues = $this->staticValues;
        if (HtmlState::Dead === $bodyContext->getState()) {
            $this->contentTypes = $inputContentTypes;
            $this->staticValues = $inputStaticValues;

            return $bodyContext;
        }

        $input = $context->nudgeAttributeValue();
        $output = $bodyContext->nudgeAttributeValue();
        if (!$input->equals($output)) {
            $this->addDiagnostic($node, DiagnosticCode::UnstableLoop, \sprintf('The "for" loop body changes the HTML context from %s to %s, so repeated iterations cannot be analyzed safely.', $input->describe(), $output->describe()));
            $this->contentTypes = $inputContentTypes;
            $this->staticValues = $inputStaticValues;

            return $context->toDead();
        }

        if (!$node->hasNode('else')) {
            $this->contentTypes = $this->joinContentTypeMaps([$bodyContentTypes, $inputContentTypes]);
            $this->staticValues = $this->joinStaticValueMaps([$bodyStaticValues, $inputStaticValues]);

            return $output;
        }

        $this->contentTypes = $inputContentTypes;
        $this->staticValues = $inputStaticValues;
        $elseContext = $this->analyzeNode($node->getNode('else'), $context, $explicitAutoescape);
        $this->contentTypes = $this->joinContentTypeMaps([$bodyContentTypes, $this->contentTypes]);
        $this->staticValues = $this->joinStaticValueMaps([$bodyStaticValues, $this->staticValues]);

        return $this->joinContexts([$output, $elseContext], $node, 'The body and "else" branch of this "for" tag end in incompatible contexts');
    }

    private function analyzeWith(WithNode $node, HtmlContext $context, string|bool|null $explicitAutoescape): HtmlContext
    {
        $contentTypes = $this->contentTypes;
        $staticValues = $this->staticValues;
        if ($node->hasNode('variables')) {
            $this->applyVariableContentTypes($node->getNode('variables'), $node->getAttribute('only'));
        } elseif ($node->getAttribute('only')) {
            $this->contentTypes = [];
            $this->staticValues = [];
        }
        $context = $this->analyzeNode($node->getNode('body'), $context, $explicitAutoescape);
        $this->contentTypes = $contentTypes;
        $this->staticValues = $staticValues;

        return $context;
    }

    private function applyVariableContentTypes(Node $variables, bool $only = false): void
    {
        if (!$variables instanceof ArrayExpression) {
            if ($only) {
                $this->contentTypes = [];
                $this->staticValues = [];
            }

            return;
        }
        $inputContentTypes = $this->contentTypes;
        $inputStaticValues = $this->staticValues;
        $outputContentTypes = $only ? [] : $inputContentTypes;
        $outputStaticValues = $only ? [] : $inputStaticValues;
        for ($i = 0; $i < \count($variables); $i += 2) {
            $name = $variables->getNode($i);
            $value = $variables->getNode(1 + $i);
            if (!$name instanceof ConstantExpression || !\is_string($name->getAttribute('value')) || !$value instanceof AbstractExpression) {
                continue;
            }
            $name = $name->getAttribute('value');
            $key = 'context:'.$name;
            $this->contentTypes = $inputContentTypes;
            $valueContentTypes = $this->inferContentTypes($value, new HtmlContext());
            if ($valueContentTypes->isPlainText()) {
                unset($outputContentTypes[$key]);
            } else {
                $outputContentTypes[$key] = $valueContentTypes;
            }
            if (null === $staticValue = $this->staticExpressionAnalyzer?->analyze($value, $inputStaticValues)) {
                unset($outputStaticValues[$key]);
            } else {
                $outputStaticValues[$key] = $staticValue->withProvenance($name);
            }
        }
        $this->contentTypes = $outputContentTypes;
        $this->staticValues = $outputStaticValues;
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
            $this->assignStaticValues($names, []);

            return $context;
        }

        $finiteValues = [];
        foreach ($values as $value) {
            $finiteValues[] = $value instanceof AbstractExpression ? $this->staticExpressionAnalyzer?->analyze($value, $this->staticValues) : null;
        }
        $this->assignStaticValues($names, $finiteValues);

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

    private function removeAssignedInferences(Node $target): void
    {
        if ($target->hasAttribute('name') && \is_string($target->getAttribute('name'))) {
            $key = 'context:'.$target->getAttribute('name');
            unset($this->staticValues[$key], $this->contentTypes[$key]);
        }
        foreach ($target as $child) {
            $this->removeAssignedInferences($child);
        }
    }

    /**
     * @param list<FiniteStaticValueSet|null> $values
     */
    private function assignStaticValues(Node $names, array $values): void
    {
        if (!$names instanceof Nodes) {
            $names = new Nodes([$names]);
        }
        foreach ($names as $index => $name) {
            if (!$name instanceof ContextVariable && !$name instanceof LocalVariable) {
                continue;
            }
            $key = $this->getVariableKey($name);
            $value = $values[$index] ?? null;
            if (null === $value) {
                unset($this->staticValues[$key]);
            } else {
                $this->staticValues[$key] = $value->withProvenance($name instanceof ContextVariable ? $name->getAttribute('name') : 'local value');
            }
        }
    }

    private function getCurrentBlockName(): ?string
    {
        return $this->blockStack ? $this->blockStack[\count($this->blockStack) - 1]['name'] : null;
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
     * @param non-empty-list<array<string, FiniteStaticValueSet>> $maps
     *
     * @return array<string, FiniteStaticValueSet>
     */
    private function joinStaticValueMaps(array $maps): array
    {
        $joined = $maps[0];
        foreach ($joined as $name => $values) {
            foreach (\array_slice($maps, 1) as $map) {
                if (!isset($map[$name]) || null === $values = $values->merge($map[$name])) {
                    unset($joined[$name]);
                    continue 2;
                }
            }
            $joined[$name] = $values;
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

    private function analyzeRegisteredNode(Node $node, HtmlContext $context): HtmlContext
    {
        $type = $this->nodeAnalyzerRegistry?->classify($node);
        if (null === $type) {
            return $this->rejectUnknownNode($node, $context);
        }
        if (NodeType::ContextPreserving === $type) {
            foreach ($this->nodeAnalyzerRegistry?->getVariableContentTypes($node) ?? [] as $name => $contentTypes) {
                $this->contentTypes['context:'.$name] = $contentTypes;
            }

            return $context;
        }
        if (NodeType::PlainTextOutput === $type) {
            $print = new PrintNode(new RuntimeOutputExpression([], [], $node->getTemplateLine()), $node->getTemplateLine());
            if (null !== $source = $node->getSourceContext()) {
                $print->setSourceContext($source);
            }

            return $this->analyzePrint($print, $context, false);
        }
        if (HtmlState::Text === $context->getState()) {
            return $context;
        }

        $this->addDiagnostic($node, DiagnosticCode::UnsupportedOutputContext, \sprintf('The "%s" node produces a complete HTML fragment, which cannot be rendered in %s.', $node::class, $context->describe()));

        return $context->toDead();
    }

    private function rejectUnknownNode(Node $node, HtmlContext $context): HtmlContext
    {
        $this->addDiagnostic($node, DiagnosticCode::UnsupportedNode, \sprintf('The "%s" node has no contextual escaping analyzer.', $node::class));

        return $context->toDead();
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

            case ForLoopNode::class:
            case EnterProfileNode::class:
            case LeaveProfileNode::class:
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
                if (null === $this->nodeAnalyzerRegistry?->classify($node)) {
                    $this->addDiagnostic($node, DiagnosticCode::UnsupportedNode, \sprintf('The "%s" node has no contextual escaping analyzer.', $node::class));
                }
        }
    }

    private function addInferredEscape(InferredEscape $inferredEscape): void
    {
        $key = serialize([
            spl_object_id($inferredEscape->getNode()),
            array_map(static fn (EscapeOperation $operation): string => $operation->name, $inferredEscape->getPlan()->getOperations()),
            $inferredEscape->getContext(),
            $inferredEscape->getProvenance(),
            $inferredEscape->getStaticOutputs(),
            $inferredEscape->getValueContracts(),
        ]);
        $duplicate = false;
        foreach (array_keys($this->alternativeInferenceFrames) as $index) {
            $count = 1 + ($this->alternativeInferenceFrames[$index]['current'][$key] ?? 0);
            $this->alternativeInferenceFrames[$index]['current'][$key] = $count;
            if ($count <= ($this->alternativeInferenceFrames[$index]['prior'][$key] ?? 0)) {
                $duplicate = true;
            }
        }
        if (!$duplicate) {
            $this->result->addInferredEscape($inferredEscape);
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
