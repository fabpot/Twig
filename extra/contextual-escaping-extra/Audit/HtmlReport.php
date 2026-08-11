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

/**
 * @internal
 *
 * @experimental
 */
final class HtmlReport
{
    public function __construct(
        private Environment $twig,
        private string $path,
        private string $projectDirectory,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @param list<array{template: string, path: string|null, line: int, operations: list<string>, context: string, current: string, correct: bool, expression: string|null, plain_variable: bool, current_safe: list<string>, current_escapes: list<array{strategy: string, scope: 'whole'|'nested', expression: string, automatic: bool}>, provenance: list<string>, static_output_count: int, value_contract: list<string>}> $plans
     * @param list<array{template: string, path: string|null, line: int, code: string, message: string}>                                                                                                                                                                                                                                                                                                                        $diagnostics
     * @param array<string, int>                                                                                                                                                                                                                                                                                                                                                                                                $unsupportedNodes
     * @param array{templates: int, output_sites: int, correct_plans: int, incorrect_plans: int, diagnostics: int}                                                                                                                                                                                                                                                                                                              $summary
     */
    public function write(array $plans, array $diagnostics, array $unsupportedNodes, array $summary): void
    {
        $entries = [];
        $operations = [];
        $templateOwnership = [];
        $assessmentCounts = ['proven' => 0, 'correct' => 0, 'partial' => 0, 'review' => 0, 'unsafe' => 0, 'unavailable' => 0];
        $viewCounts = ['action' => 0, 'review' => 0, 'future' => 0, 'no-urgent' => 0, 'all' => \count($plans) + \count($diagnostics)];
        $ownershipCounts = ['application' => 0, 'dependency' => 0];

        foreach ($plans as $plan) {
            $assessment = $this->assessPlan($plan);
            $ownership = $this->classifyOwnership($plan['path']);
            $current = 'none' === $plan['current'] && $plan['current_safe'] ? 'safe for '.implode(', ', $plan['current_safe']) : $plan['current'];
            $contextReason = 'proven' === $assessment['status']
                ? \sprintf('%d possible static output%s %s analyzed directly in %s.', $plan['static_output_count'], 1 === $plan['static_output_count'] ? '' : 's', 1 === $plan['static_output_count'] ? 'was' : 'were', $plan['context'])
                : $this->describeContextReason($plan['context']);
            $search = strtolower(implode(' ', [
                $plan['template'],
                $plan['line'],
                $ownership,
                implode(' ', $plan['operations']),
                $plan['context'],
                $contextReason,
                implode(' ', $plan['provenance']),
                implode(' ', $plan['value_contract']),
                $current,
                implode(' ', array_map(static fn (array $escape): string => implode(' ', [$escape['scope'], $escape['expression'], $escape['strategy'], $escape['automatic'] ? 'automatic' : 'explicit']), $plan['current_escapes'])),
                $assessment['label'],
                $assessment['title'],
                $assessment['guidance'],
                $plan['expression'],
            ]));

            $entries[$plan['template']][] = [
                'type' => 'plan',
                'assessment' => $assessment,
                'ownership' => $ownership,
                'current_label' => $current,
                'context_reason' => $contextReason,
                'search' => $search,
                'source' => $this->createSourceExcerpt($plan['path'], $plan['line'], $plan['expression']),
                ...$plan,
            ];
            $templateOwnership[$plan['template']] = $ownership;
            ++$assessmentCounts[$assessment['status']];
            ++$ownershipCounts[$ownership];
            foreach ($assessment['views'] as $view) {
                ++$viewCounts[$view];
            }
            if ($assessment['unavailable']) {
                ++$assessmentCounts['unavailable'];
            }
            foreach ($plan['operations'] as $operation) {
                $operations[$operation] = true;
            }
        }

        foreach ($diagnostics as $diagnostic) {
            $assessment = $this->assessDiagnostic($diagnostic['code']);
            $ownership = $this->classifyOwnership($diagnostic['path']);
            $entries[$diagnostic['template']][] = [
                'type' => 'diagnostic',
                'assessment' => $assessment,
                'ownership' => $ownership,
                'search' => strtolower(implode(' ', [$diagnostic['template'], $diagnostic['line'], $ownership, $diagnostic['code'], $diagnostic['message'], $assessment['label'], $assessment['guidance']])),
                'source' => $this->createSourceExcerpt($diagnostic['path'], $diagnostic['line'], null),
                ...$diagnostic,
            ];
            $templateOwnership[$diagnostic['template']] = $ownership;
            ++$viewCounts['action'];
            ++$ownershipCounts[$ownership];
        }

        uksort($entries, static fn (string $left, string $right): int => [$templateOwnership[$left], $left] <=> [$templateOwnership[$right], $right]);
        ksort($operations);

        $navigationEntries = [];
        $templates = [];
        foreach ($entries as $template => $templateEntries) {
            $id = 'template-'.substr(hash('sha256', $template), 0, 12);
            $navigationEntries[$template] = [
                'id' => $id,
                'count' => \count($templateEntries),
                'ownership' => $templateOwnership[$template],
            ];
            $path = null;
            foreach ($templateEntries as $entry) {
                $path ??= $entry['path'];
            }
            $templates[] = [
                'id' => $id,
                'name' => $template,
                'source_uri' => null === $path ? null : $this->fileUri($path),
                'findings' => $templateEntries,
            ];
        }

        $unsupported = [];
        foreach ($unsupportedNodes as $message => $count) {
            $unsupported[] = ['message' => $message, 'count' => $count];
        }

        $html = $this->twig->render('report.html.twig', [
            'generated_at' => gmdate('Y-m-d H:i:s').' UTC',
            'summary' => $summary,
            'assessment_counts' => $assessmentCounts,
            'view_counts' => $viewCounts,
            'ownership_counts' => $ownershipCounts,
            'operations' => array_keys($operations),
            'navigation' => $this->buildNavigation($navigationEntries),
            'templates' => $templates,
            'unsupported_nodes' => $unsupported,
        ]);

        $directory = \dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unable to create the HTML report directory "%s".', $directory));
        }
        if (false === file_put_contents($this->path, $html)) {
            throw new \RuntimeException(\sprintf('Unable to write the HTML report to "%s".', $this->path));
        }
    }

    /**
     * @param array<string, array{id: string, count: int, ownership: 'application'|'dependency'}> $entries
     *
     * @return array<string, array{count: int, directories: array, files: array}>
     */
    private function buildNavigation(array $entries): array
    {
        $trees = [
            'application' => ['count' => 0, 'directories' => [], 'files' => []],
            'dependency' => ['count' => 0, 'directories' => [], 'files' => []],
        ];
        foreach ($entries as $template => $entry) {
            $parts = explode('/', $template);
            $file = array_pop($parts);
            $trees[$entry['ownership']]['count'] += $entry['count'];
            $node = &$trees[$entry['ownership']];
            foreach ($parts as $part) {
                $node['directories'][$part] ??= ['count' => 0, 'directories' => [], 'files' => []];
                $node['directories'][$part]['count'] += $entry['count'];
                $node = &$node['directories'][$part];
            }
            $node['files'][$file] = ['template' => $template, ...$entry];
            unset($node);
        }

        foreach ($trees as $ownership => $tree) {
            if (!$tree['count']) {
                unset($trees[$ownership]);

                continue;
            }
            $trees[$ownership] = $this->sortNavigationNode($tree);
        }

        return $trees;
    }

    private function sortNavigationNode(array $node): array
    {
        ksort($node['directories']);
        ksort($node['files']);
        foreach ($node['directories'] as $name => $directory) {
            $node['directories'][$name] = $this->sortNavigationNode($directory);
        }

        return $node;
    }

    /**
     * @param array{operations: list<string>, current: string, correct: bool, expression?: string|null, plain_variable?: bool, current_safe?: list<string>, provenance?: list<string>} $plan
     *
     * @return array{status: 'proven'|'correct'|'partial'|'review'|'unsafe', label: string, assessments: list<string>, views: list<'action'|'review'|'future'|'no-urgent'>, unavailable: bool, title: string, guidance: string}
     */
    private function assessPlan(array $plan): array
    {
        $operations = $plan['operations'];
        if (!$operations && ($plan['provenance'] ?? [])) {
            return [
                'status' => 'proven',
                'label' => 'Statically proven safe',
                'assessments' => ['proven'],
                'views' => ['no-urgent'],
                'unavailable' => false,
                'title' => 'No escaping operation is required',
                'guidance' => 'Every possible output comes from template-defined constants and was analyzed directly in its output context.',
            ];
        }

        $outerOperation = $operations[array_key_last($operations)];
        $outerProtected = $this->hasOuterProtection($outerOperation, $plan['current']);
        $unavailable = !$plan['correct'] && [] !== array_intersect($operations, [
            'HtmlRcdata',
            'JavaScriptValue',
            'JavaScriptTemplateString',
            'JavaScriptRegExp',
            'CssValue',
            'MetaRefreshDelay',
            'SrcsetFilter',
            'UrlSchemeFilter',
            'UrlNormalize',
        ]);
        $needsUrlTrust = \in_array('UrlSchemeFilter', $operations, true);
        $generatedOutput = 'none' === $plan['current'] && isset($plan['expression']) && str_starts_with(ltrim($plan['expression']), '<');

        if ($plan['correct']) {
            $status = 'correct';
            $label = 'Current plan matches';
            $title = 'No change is needed';
            $guidance = 'The current escaping strategy matches the inferred contextual operation.';
        } elseif ($outerProtected) {
            $status = 'partial';
            $label = \in_array($outerOperation, ['HtmlAttribute', 'HtmlAttributeUnquoted'], true) ? 'Outer HTML protection present' : 'Outer protection present';
            $title = 'Review the missing inner operations';
            $guidance = 'The current strategy protects the enclosing output context, but it does not provide every operation in the inferred pipeline.';
        } elseif ($generatedOutput) {
            $status = 'review';
            $label = 'Generated output to review';
            $title = 'Check the extension runtime safety contract';
            $guidance = 'A custom lexer or component transformed this source. Confirm that its runtime escapes untrusted values and declares accurate safe-content metadata; do not add a template filter blindly.';
        } elseif ('none' === $plan['current'] && ($plan['current_safe'] ?? [])) {
            $status = 'review';
            $label = 'Trusted by current Twig';
            $title = 'Verify the legacy safe-content contract';
            $guidance = \sprintf('Current Twig considers this expression safe for %s and intentionally applies no escaping. Confirm that this legacy safety declaration is valid in the inferred context.', implode(', ', $plan['current_safe']));
        } elseif ('none' === $plan['current'] && !($plan['plain_variable'] ?? false)) {
            $status = 'review';
            $label = 'Expression safety to review';
            $title = 'Review the expression value contract';
            $guidance = 'No escaping strategy or current safe-content declaration was found. Confirm that the expression can only produce context-safe values before changing the template.';
        } else {
            $status = 'unsafe';
            $label = 'Unsafe today';
            $title = 'Add context-appropriate protection';
            $guidance = 'No recognized current strategy protects the enclosing output context. Avoid raw output and add the escaping or validation required by this context.';
        }

        if ($needsUrlTrust) {
            $title = 'Validate the complete URL or declare trusted metadata';
            $guidance = 'For untrusted values, validate and normalize the URL in PHP with a strict scheme allow-list. For a trusted URL-producing callable, declare is_safe: [\'url\']. Do not apply e(\'url\') to a complete URL.';
        } elseif (\in_array('UrlPath', $operations, true) || \in_array('UrlQuery', $operations, true)) {
            $title = 'Encode the dynamic URL component';
            $guidance = 'Keep the URL structure static and apply e(\'url\') only to the dynamic path, query, or fragment component. Keep the HTML attribute quoted.';
        } elseif ('partial' === $status && ['HtmlAttribute'] === $operations) {
            $title = 'Keep this HTML attribute quoted';
            $guidance = 'Current html escaping already prevents quoted-attribute breakout. No urgent template change is needed; html_attr is the exact contextual operation.';
        } elseif ($unavailable) {
            $title = 'No direct Twig filter implements this pipeline';
            $guidance = 'Do not mechanically combine existing filters. Validate or serialize the value in trusted application code until Twig provides the contextual operation.';
        }

        $assessments = [$status];
        if ($needsUrlTrust) {
            $assessments[] = 'url-trust';
        }
        if ($unavailable) {
            $assessments[] = 'unavailable';
        }
        $views = match ($status) {
            'unsafe' => ['action'],
            'review' => ['review'],
            'correct', 'partial' => ['no-urgent'],
        };
        if ($unavailable) {
            $views[] = 'future';
        }

        return [
            'status' => $status,
            'label' => $label,
            'assessments' => $assessments,
            'views' => $views,
            'unavailable' => $unavailable,
            'title' => $title,
            'guidance' => $guidance,
        ];
    }

    private function hasOuterProtection(string $operation, string $current): bool
    {
        $strategies = explode(' | ', $current);
        $expected = match ($operation) {
            'HtmlText', 'HtmlRcdata' => ['html'],
            'HtmlAttribute' => ['html', 'html_attr', 'html_attr_relaxed'],
            'HtmlAttributeUnquoted' => ['html_attr', 'html_attr_relaxed'],
            'JavaScriptString' => ['js', 'js_string'],
            'JavaScriptTemplateString' => ['js_template'],
            'JavaScriptRegExp' => ['js_regexp'],
            'CssString' => ['css', 'css_string'],
            default => [],
        };

        return [] !== array_intersect($strategies, $expected);
    }

    private function describeContextReason(string $context): string
    {
        return match ($context) {
            'HTML text' => 'The expression is rendered as HTML text.',
            'JavaScript Code' => 'The expression is rendered as executable JavaScript code.',
            'JavaScript DoubleQuotedString' => 'The expression is inside a double-quoted JavaScript string.',
            'JavaScript SingleQuotedString' => 'The expression is inside a single-quoted JavaScript string.',
            'JavaScript TemplateString' => 'The expression is inside a JavaScript template string.',
            'JavaScript RegExp' => 'The expression is inside a JavaScript regular expression.',
            'CSS Value' => 'The expression is in a CSS declaration value.',
            'CSS DoubleQuotedString' => 'The expression is inside a double-quoted CSS string.',
            'CSS SingleQuotedString' => 'The expression is inside a single-quoted CSS string.',
            'CSS UrlStart' => 'The expression starts a CSS url() value.',
            'CSS UrlUnquoted' => 'The expression is inside an unquoted CSS url() value.',
            'CSS UrlDoubleQuoted' => 'The expression is inside a double-quoted CSS url() value.',
            'CSS UrlSingleQuoted' => 'The expression is inside a single-quoted CSS url() value.',
            'srcset candidate start' => 'The expression starts a new srcset candidate.',
            default => $this->describeAttributeContextReason($context),
        };
    }

    private function describeAttributeContextReason(string $context): string
    {
        if (!preg_match('/^(?:a|an) (double-quoted|single-quoted|unquoted) (.+) attribute$/', $context, $matches)) {
            return 'The expression is rendered in '.$context.'.';
        }

        $attribute = 'unquoted' === $matches[1] ? 'an unquoted HTML attribute' : 'a '.$matches[1].' HTML attribute';

        return match ($matches[2]) {
            'plain HTML' => 'The expression is inside '.$attribute.'.',
            'URL start' => 'The expression starts a URL in '.$attribute.'.',
            'URL path' => 'The expression is in the path of a URL in '.$attribute.'.',
            'URL query or fragment' => 'The expression is in the query or fragment of a URL in '.$attribute.'.',
            default => 'The expression is rendered in '.$context.'.',
        };
    }

    /**
     * @return 'application'|'dependency'
     */
    private function classifyOwnership(?string $path): string
    {
        if (null === $path) {
            return 'application';
        }

        $projectDirectory = str_replace('\\', '/', realpath($this->projectDirectory) ?: $this->projectDirectory);
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        $inProject = $path === $projectDirectory || str_starts_with($path, rtrim($projectDirectory, '/').'/');
        $inVendor = str_starts_with($path, rtrim($projectDirectory, '/').'/vendor/');

        return $inProject && !$inVendor ? 'application' : 'dependency';
    }

    /**
     * @return array{assessment: 'diagnostic-ambiguity'|'diagnostic-error'|'diagnostic-limitation', label: string, title: string, guidance: string}
     */
    private function assessDiagnostic(string $code): array
    {
        if (str_starts_with($code, 'Ambiguous') || str_starts_with($code, 'Incomplete') || 'UnstableLoop' === $code) {
            return [
                'assessment' => 'diagnostic-ambiguity',
                'label' => 'Ambiguous template context',
                'title' => 'Make every possible path end in the same context',
                'guidance' => 'Add static delimiters or restructure the branches so the HTML, CSS, JavaScript, or URL parser state is identical before later output.',
            ];
        }

        if (\in_array($code, ['SyntaxError', 'MismatchedExplicitEscaping'], true)) {
            return [
                'assessment' => 'diagnostic-error',
                'label' => 'Template error',
                'title' => 'Fix the template before contextual analysis',
                'guidance' => 'Correct the syntax, remove deprecated template constructs, or use a supported explicit escaping strategy, then run the audit again.',
            ];
        }

        return [
            'assessment' => 'diagnostic-limitation',
            'label' => 'Analyzer limitation',
            'title' => 'Keep this structure static or provide a supported semantic contract',
            'guidance' => 'The analyzer cannot follow this composition or structural output yet. Prefer static template references and complete static HTML structure; do not add an escaping filter blindly.',
        ];
    }

    /**
     * @return array{expression: string|null, uri: string|null, lines: list<array{number: int, before: string, highlight: string|null, after: string}>}|null
     */
    private function createSourceExcerpt(?string $path, int $line, ?string $expression): ?array
    {
        if (null === $path || !is_file($path) || false === $code = file_get_contents($path)) {
            return null === $expression ? null : ['expression' => $expression, 'uri' => null, 'lines' => []];
        }

        $code = str_replace(["\r\n", "\r"], "\n", $code);
        $lines = explode("\n", $code);
        $target = max(0, min(\count($lines) - 1, $line - 1));
        $starts = [];
        $offset = 0;
        foreach ($lines as $sourceLine) {
            $starts[] = $offset;
            $offset += \strlen($sourceLine) + 1;
        }

        $range = $this->findHighlightRange($code, $lines, $starts, $target, $expression);
        $rangeEndLine = $target;
        foreach ($starts as $index => $start) {
            if ($start >= $range[1]) {
                break;
            }
            $rangeEndLine = $index;
        }
        $first = max(0, $target - 2);
        $last = min(\count($lines) - 1, max($target + 2, $rangeEndLine + 2));
        $sourceLines = [];
        for ($index = $first; $index <= $last; ++$index) {
            $sourceLine = $lines[$index];
            $start = $starts[$index];
            $end = $start + \strlen($sourceLine);
            $highlightStart = max($start, $range[0]);
            $highlightEnd = min($end, $range[1]);
            $before = $sourceLine;
            $highlight = null;
            $after = '';
            if ($highlightStart < $highlightEnd) {
                $before = substr($sourceLine, 0, $highlightStart - $start);
                $highlight = substr($sourceLine, $highlightStart - $start, $highlightEnd - $highlightStart);
                $after = substr($sourceLine, $highlightEnd - $start);
            }
            $sourceLines[] = [
                'number' => 1 + $index,
                'before' => $before,
                'highlight' => $highlight,
                'after' => $after,
            ];
        }

        return ['expression' => null, 'uri' => $this->fileUri($path), 'lines' => $sourceLines];
    }

    /**
     * @param list<string> $lines
     * @param list<int>    $starts
     *
     * @return array{int, int}
     */
    private function findHighlightRange(string $code, array $lines, array $starts, int $target, ?string $expression): array
    {
        $lineStart = $starts[$target];
        $lineEnd = $lineStart + \strlen($lines[$target]);
        if (null !== $expression && str_starts_with(ltrim($expression), '<twig:')) {
            if (false !== $opening = stripos($code, '<twig:', $lineStart)) {
                if ($opening <= $lineEnd && null !== $closing = $this->findTagClosing($code, $opening + 6)) {
                    return [$opening, $closing + 1];
                }
            }
        }
        if (null !== $expression && str_starts_with($expression, '{{')) {
            if (false !== $exact = strpos($code, $expression, $lineStart)) {
                if ($exact <= $lineEnd) {
                    return [$exact, $exact + \strlen($expression)];
                }
            }
            if (false !== $opening = strpos($code, '{{', $lineStart)) {
                if ($opening <= $lineEnd && null !== $closing = $this->findExpressionClosing($code, $opening + 2)) {
                    return [$opening, $closing + 2];
                }
            }
        }
        if (null !== $expression && false !== $exact = strpos($lines[$target], $expression)) {
            return [$lineStart + $exact, $lineStart + $exact + \strlen($expression)];
        }

        return [$lineStart, $lineEnd];
    }

    private function findTagClosing(string $code, int $offset): ?int
    {
        $quote = null;
        $length = \strlen($code);
        for ($i = $offset; $i < $length; ++$i) {
            $character = $code[$i];
            if (null !== $quote) {
                if ($quote === $character && '\\' !== ($code[$i - 1] ?? null)) {
                    $quote = null;
                }

                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;

                continue;
            }
            if ('>' === $character) {
                return $i;
            }
        }

        return null;
    }

    private function findExpressionClosing(string $code, int $offset): ?int
    {
        $delimiters = [];
        $quote = null;
        $escaped = false;
        $length = \strlen($code);
        for ($i = $offset; $i < $length; ++$i) {
            $character = $code[$i];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }

                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;

                continue;
            }
            if ('}' === $character && '}' === ($code[$i + 1] ?? null) && [] === $delimiters) {
                return $i;
            }
            if (isset(['(' => true, '[' => true, '{' => true][$character])) {
                $delimiters[] = $character;

                continue;
            }
            if (isset([')' => '(', ']' => '[', '}' => '{'][$character]) && end($delimiters) === [')' => '(', ']' => '[', '}' => '{'][$character]) {
                array_pop($delimiters);
            }
        }

        return null;
    }

    private function fileUri(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return 'file://'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
