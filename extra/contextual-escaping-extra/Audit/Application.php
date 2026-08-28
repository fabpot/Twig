<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Audit;

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Extra\ContextualEscaping\Analysis\CurrentEscapingSafetyAnalyzer;
use Twig\Extra\ContextualEscaping\Analysis\DiagnosticCode;
use Twig\Extra\ContextualEscaping\Analysis\EscapeFilter;
use Twig\Extra\ContextualEscaping\Analysis\EscapeOperation;
use Twig\Extra\ContextualEscaping\Analysis\ValueContract;
use Twig\Extra\ContextualEscaping\Linter;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\OperatorEscapeInterface;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @internal
 *
 * @experimental
 */
final class Application
{
    /** @var array<string, string|null> */
    private array $templatePaths = [];

    public function __construct(
        private Environment $twig,
        private string $templateDirectory,
        private HtmlReport $htmlReport,
        private CurrentEscapingSafetyAnalyzer $currentSafetyAnalyzer,
        private Baseline $baseline,
        private ExpressionLocator $expressionLocator,
    ) {
    }

    public function run(?string $baselinePath = null): int
    {
        $templateCount = 0;
        $outputSites = [];
        $escapePlans = [];
        $staticPlans = [];
        $diagnostics = [];
        $unsupportedNodes = [];
        $planRows = [];
        $diagnosticRows = [];

        foreach (Linter::create($this->twig, $this->currentSafetyAnalyzer)->lintDirectory($this->templateDirectory) as $name => $result) {
            ++$templateCount;

            foreach ($result->getInferredEscapes() as $inferredEscape) {
                $node = $inferredEscape->getNode();
                $templateName = $node->getTemplateName() ?? $name;
                $operationCases = $inferredEscape->getPlan()->getOperations();
                $operations = array_map(static fn (EscapeOperation $operation): string => $operation->name, $operationCases);
                $expression = $this->getExpressionSnippet($node);
                $expressionNode = $node->getNode('expr');
                $currentStrategies = $this->getCurrentEscapingStrategies($expressionNode);
                $currentEscapes = $this->getCurrentEscapes($expressionNode);
                $currentSafety = $this->currentSafetyAnalyzer->analyze($expressionNode);
                $currentIsCorrect = $this->currentEscapingIsCorrect($operationCases, $currentStrategies);
                $current = $currentStrategies ? implode(' | ', $currentStrategies) : 'none';
                $siteKey = $templateName."\0".$node->getTemplateLine()."\0".($expression ?? '');
                $planKey = $siteKey."\0".implode("\0", $operations);
                $outputSites[$siteKey] = true;

                $provenance = $inferredEscape->getProvenance();
                if ([] === $operations) {
                    if (!$provenance || isset($staticPlans[$planKey])) {
                        continue;
                    }
                    $staticPlans[$planKey] = true;
                } elseif ([EscapeOperation::HtmlText] === $operationCases && $currentIsCorrect) {
                    continue;
                } elseif (isset($escapePlans[$planKey])) {
                    continue;
                } else {
                    $escapePlans[$planKey] = $currentIsCorrect;
                }

                $sourcePath = $node->getSourceContext()?->getPath();
                $planRows[] = [
                    'template' => $templateName,
                    'path' => $sourcePath ?: null,
                    'line' => $node->getTemplateLine(),
                    'operations' => $operations,
                    'context' => $inferredEscape->getContext(),
                    'current' => $current,
                    'correct' => $currentIsCorrect,
                    'expression' => $expression,
                    'plain_variable' => $expressionNode instanceof ContextVariable,
                    'current_safe' => $currentSafety['safe'],
                    'current_escapes' => $currentEscapes,
                    'provenance' => $provenance,
                    'static_output_count' => \count($inferredEscape->getStaticOutputs()),
                    'value_contracts' => array_map(static fn (ValueContract $contract): array => [
                        'expression' => $contract->getExpression(),
                        'implementation' => $contract->getImplementation(),
                        'content_type' => $contract->getContentType()->name,
                        'source' => $contract->getSource(),
                    ], $inferredEscape->getValueContracts()),
                ];
                if ($operations) {
                    printf(
                        "%s:%d [EscapePlan] %s [Current: %s, %s]%s\n",
                        $templateName,
                        $node->getTemplateLine(),
                        implode(' -> ', $operations),
                        $current,
                        $currentIsCorrect ? 'correct' : 'incorrect',
                        null === $expression ? '' : ': '.$expression,
                    );
                }
            }

            foreach ($result->getDiagnostics() as $diagnostic) {
                $templateName = $diagnostic->getTemplateName() ?? $name;
                $key = $templateName."\0".$diagnostic->getTemplateLine()."\0".$diagnostic->getCode()->name."\0".$diagnostic->getMessage();
                if (isset($diagnostics[$key])) {
                    continue;
                }
                $diagnostics[$key] = true;

                if (DiagnosticCode::UnsupportedNode === $diagnostic->getCode()) {
                    $unsupportedNodes[$diagnostic->getMessage()] = 1 + ($unsupportedNodes[$diagnostic->getMessage()] ?? 0);

                    continue;
                }

                $diagnosticRows[] = [
                    'template' => $templateName,
                    'path' => $this->getTemplatePath($templateName),
                    'line' => $diagnostic->getTemplateLine(),
                    'code' => $diagnostic->getCode()->name,
                    'message' => $diagnostic->getMessage(),
                ];
                fprintf(
                    \STDERR,
                    "%s:%d [%s] %s\n",
                    $templateName,
                    $diagnostic->getTemplateLine(),
                    $diagnostic->getCode()->name,
                    $diagnostic->getMessage(),
                );
            }
        }

        ksort($unsupportedNodes);
        foreach ($unsupportedNodes as $message => $count) {
            fprintf(\STDERR, "[UnsupportedNode] %d occurrence%s: %s\n", $count, 1 === $count ? '' : 's', $message);
        }

        $correctPlanCount = \count(array_filter($escapePlans));
        $incorrectPlanCount = \count($escapePlans) - $correctPlanCount;
        printf(
            "Analyzed %d template%s and %d output site%s; found %d contextual escape plan%s (%d correct, %d incorrect) and %d diagnostic%s.\n",
            $templateCount,
            1 === $templateCount ? '' : 's',
            \count($outputSites),
            1 === \count($outputSites) ? '' : 's',
            \count($escapePlans),
            1 === \count($escapePlans) ? '' : 's',
            $correctPlanCount,
            $incorrectPlanCount,
            \count($diagnostics),
            1 === \count($diagnostics) ? '' : 's',
        );

        if ($staticPlans) {
            printf("Proved %d finite static output site%s safe.\n", \count($staticPlans), 1 === \count($staticPlans) ? '' : 's');
        }

        $jsonReport = $this->baseline->create($planRows, $diagnosticRows, $unsupportedNodes);
        $diff = null === $baselinePath ? null : $this->baseline->compare($baselinePath, $jsonReport);
        $this->baseline->write($jsonReport);

        $this->htmlReport->write($planRows, $diagnosticRows, $unsupportedNodes, [
            'templates' => $templateCount,
            'output_sites' => \count($outputSites),
            'correct_plans' => $correctPlanCount,
            'incorrect_plans' => $incorrectPlanCount,
            'diagnostics' => \count($diagnostics),
        ]);
        printf("HTML report: %s\n", $this->htmlReport->getPath());
        printf("JSON report: %s\n", $this->baseline->getPath());
        if (null !== $diff) {
            printf("Baseline diff: %d new, %d resolved, %d unchanged.\n", $diff['new'], $diff['resolved'], $diff['unchanged']);

            return $diff['new'] ? 1 : 0;
        }

        return $diagnostics || $incorrectPlanCount ? 1 : 0;
    }

    private function getTemplatePath(string $name): ?string
    {
        if (\array_key_exists($name, $this->templatePaths)) {
            return $this->templatePaths[$name];
        }

        try {
            $path = $this->twig->getLoader()->getSourceContext($name)->getPath();
        } catch (LoaderError) {
            $path = '';
        }

        return $this->templatePaths[$name] = $path ?: null;
    }

    /**
     * @return list<string>
     */
    private function getCurrentEscapingStrategies(AbstractExpression $expression): array
    {
        if ($expression instanceof FilterExpression) {
            if (null !== $strategy = $this->getEscapeStrategy($expression)) {
                return [$strategy];
            }

            $filter = $expression->getAttribute('twig_callable');
            $input = $expression->getNode('node');
            if (!$filter instanceof TwigFilter || !$input instanceof AbstractExpression) {
                return [];
            }
            $strategies = $this->getCurrentEscapingStrategies($input);
            if ('raw' === $filter->getName() || \in_array('all', $filter->getPreservesSafety(), true)) {
                return $strategies;
            }

            return array_values(array_intersect($strategies, $filter->getPreservesSafety()));
        }

        if ($expression instanceof OperatorEscapeInterface) {
            $strategies = null;
            foreach ($expression->getOperandNamesToEscape() as $name) {
                $operand = $expression->getNode($name);
                if (!$operand instanceof AbstractExpression || $this->currentSafetyAnalyzer->analyze($operand)['constant_output']) {
                    continue;
                }
                $operandStrategies = $this->getCurrentEscapingStrategies($operand);
                $strategies = null === $strategies ? $operandStrategies : array_intersect($strategies, $operandStrategies);
            }

            return array_values($strategies ?? []);
        }

        return [];
    }

    /**
     * @return list<array{strategy: string, scope: 'whole'|'nested', expression: string, automatic: bool}>
     */
    private function getCurrentEscapes(AbstractExpression $expression): array
    {
        $escapes = [];
        $seen = [];
        $this->collectCurrentEscapes($expression, true, $escapes, $seen);

        return $escapes;
    }

    /**
     * @param list<array{strategy: string, scope: 'whole'|'nested', expression: string, automatic: bool}> $escapes
     * @param array<int, true>                                                                            $seen
     */
    private function collectCurrentEscapes(Node $node, bool $wholeOutput, array &$escapes, array &$seen): void
    {
        $id = spl_object_id($node);
        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;

        if ($node instanceof FilterExpression && null !== $strategy = $this->getEscapeStrategy($node)) {
            $input = $node->getNode('node');
            $escapes[] = [
                'strategy' => $strategy,
                'scope' => $wholeOutput ? 'whole' : 'nested',
                'expression' => $input instanceof AbstractExpression ? $this->describeExpression($input) : 'expression',
                'automatic' => EscapeFilter::isAutomatic($node),
            ];
            $this->collectCurrentEscapes($input, false, $escapes, $seen);

            return;
        }

        foreach ($node as $child) {
            $this->collectCurrentEscapes($child, false, $escapes, $seen);
        }
    }

    private function getEscapeStrategy(FilterExpression $expression): ?string
    {
        if (!EscapeFilter::matches($expression)) {
            return null;
        }

        return EscapeFilter::getConstantStrategy($expression) ?? 'dynamic';
    }

    private function describeExpression(AbstractExpression $expression): string
    {
        if ($expression instanceof ContextVariable) {
            return $expression->getAttribute('name');
        }
        if ($expression instanceof FunctionExpression && $expression->getAttribute('twig_callable') instanceof TwigFunction) {
            return $expression->getAttribute('twig_callable')->getName().'()';
        }

        return 'expression';
    }

    /**
     * @param list<EscapeOperation> $operations
     * @param list<string>          $currentStrategies
     */
    private function currentEscapingIsCorrect(array $operations, array $currentStrategies): bool
    {
        if (!$operations) {
            return true;
        }
        if (1 !== \count($operations) || 1 !== \count($currentStrategies)) {
            return false;
        }

        $operation = $operations[0];

        return match ($currentStrategies[0]) {
            'html' => EscapeOperation::HtmlText === $operation,
            'html_attr', 'html_attr_relaxed' => EscapeOperation::HtmlAttribute === $operation,
            'js', 'js_string' => EscapeOperation::JavaScriptString === $operation,
            'js_template' => EscapeOperation::JavaScriptTemplateString === $operation,
            'js_regexp' => EscapeOperation::JavaScriptRegExp === $operation,
            'css' => \in_array($operation, [EscapeOperation::CssValue, EscapeOperation::CssString], true),
            'css_string' => EscapeOperation::CssString === $operation,
            default => false,
        };
    }

    private function getExpressionSnippet(PrintNode $node): ?string
    {
        $source = $node->getSourceContext();
        if (null === $source) {
            return null;
        }

        $code = $source->getCode();
        $path = $source->getPath();
        $originalCode = '' !== $path && is_file($path) ? file_get_contents($path) : false;
        if (false !== $originalCode && $originalCode !== $code) {
            $originalLine = $this->getSourceLine($originalCode, $node->getTemplateLine());
            if ($originalLine !== $this->getSourceLine($code, $node->getTemplateLine())) {
                $originalLine = trim($originalLine ?? '');

                return '' === $originalLine ? null : $originalLine;
            }
            $code = $originalCode;
        }

        $lineStart = 0;
        for ($line = 1; $line < $node->getTemplateLine(); ++$line) {
            if (false === $lineStart = strpos($code, "\n", $lineStart)) {
                return null;
            }
            ++$lineStart;
        }
        $lineEnd = strpos($code, "\n", $lineStart);
        if (false === $lineEnd) {
            $lineEnd = \strlen($code);
        }

        $openings = [];
        $offset = $lineStart;
        while (false !== $opening = strpos($code, '{{', $offset)) {
            if ($opening >= $lineEnd) {
                break;
            }
            $openings[] = $opening;
            $offset = $opening + 2;
        }

        if (1 !== \count($openings) || null === $closing = $this->expressionLocator->findExpressionClosing($code, $openings[0] + 2)) {
            $line = trim(substr($code, $lineStart, $lineEnd - $lineStart));

            return '' === $line ? null : $line;
        }

        $expression = preg_replace('/\h*\R\h*/', ' ', trim(substr($code, $openings[0] + 2, $closing - $openings[0] - 2)));

        return '{{ '.$expression.' }}';
    }

    private function getSourceLine(string $code, int $line): ?string
    {
        $lines = preg_split('/\R/', $code);

        return $lines[$line - 1] ?? null;
    }
}
