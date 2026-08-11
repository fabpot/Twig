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

/**
 * @internal
 *
 * @experimental
 */
final class ReportDataBuilder
{
    public function __construct(
        private FindingAssessor $findingAssessor,
        private SourceExcerptBuilder $sourceExcerptBuilder,
        private string $projectDirectory,
    ) {
    }

    /**
     * @param list<array{template: string, path: string|null, line: int, operations: list<string>, context: string, current: string, correct: bool, expression: string|null, plain_variable: bool, current_safe: list<string>, current_escapes: list<array{strategy: string, scope: 'whole'|'nested', expression: string, automatic: bool}>, provenance: list<string>, static_output_count: int, value_contracts: list<array{expression: string, implementation: string, content_type: string, source: string}>}> $plans
     * @param list<array{template: string, path: string|null, line: int, code: string, message: string}>                                                                                                                                                                                                                                                                                                                                                                                                          $diagnostics
     * @param array<string, int>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  $unsupportedNodes
     * @param array{templates: int, output_sites: int, correct_plans: int, incorrect_plans: int, diagnostics: int}                                                                                                                                                                                                                                                                                                                                                                                                $summary
     *
     * @return array<string, mixed>
     */
    public function build(array $plans, array $diagnostics, array $unsupportedNodes, array $summary): array
    {
        $entries = [];
        $operations = [];
        $templateOwnership = [];
        $assessmentCounts = ['proven' => 0, 'correct' => 0, 'partial' => 0, 'review' => 0, 'unsafe' => 0, 'unavailable' => 0];
        $viewCounts = ['action' => 0, 'review' => 0, 'future' => 0, 'no-urgent' => 0, 'all' => \count($plans) + \count($diagnostics)];
        $ownershipCounts = ['application' => 0, 'dependency' => 0];

        foreach ($plans as $plan) {
            $assessment = $this->findingAssessor->assessPlan($plan);
            $pipelineOperations = $this->describeOperations($plan['operations']);
            $coveredOperations = $this->describeOperations($assessment['covered_operations']);
            $missingOperations = $this->describeOperations($assessment['missing_operations']);
            $protection = [
                'covered' => $coveredOperations,
                'missing' => $missingOperations,
                'covered_tone' => $coveredOperations || 'proven' === $assessment['status'] ? 'positive' : 'neutral',
                'missing_tone' => $missingOperations ? 'negative' : 'positive',
                'covered_empty' => match ($assessment['status']) {
                    'proven' => 'Every possible value is statically proven safe in this context.',
                    'review' => 'No protection is established until the trust declaration is verified.',
                    default => 'No recognized protection covers this output context.',
                },
                'missing_empty' => 'Nothing is missing for this output context.',
                'missing_title' => !$missingOperations ? 'Protection gap' : ('review' === $assessment['status'] ? 'Protection to verify' : 'Still missing'),
            ];
            $currentEscapes = array_map($this->describeCurrentEscape(...), $plan['current_escapes']);
            $ownership = $this->classifyOwnership($plan['path']);
            $current = 'none' === $plan['current'] && $plan['current_safe'] ? 'safe for '.implode(', ', $plan['current_safe']) : $plan['current'];
            $contextReason = 'proven' === $assessment['status']
                ? \sprintf('%d possible static output%s %s analyzed directly in %s.', $plan['static_output_count'], 1 === $plan['static_output_count'] ? '' : 's', 1 === $plan['static_output_count'] ? 'was' : 'were', $plan['context'])
                : $this->findingAssessor->describeContextReason($plan['context']);
            $valueContracts = array_map($this->describeValueContract(...), $plan['value_contracts']);
            $search = strtolower(implode(' ', [
                $plan['template'],
                $plan['line'],
                $ownership,
                implode(' ', array_map(static fn (array $operation): string => implode(' ', $operation), $pipelineOperations)),
                $plan['context'],
                $contextReason,
                implode(' ', $plan['provenance']),
                implode(' ', array_map(static fn (array $contract): string => implode(' ', $contract), $valueContracts)),
                $current,
                implode(' ', array_map(static fn (array $escape): string => implode(' ', [$escape['scope'], $escape['expression'], $escape['strategy'], $escape['label'], $escape['automatic'] ? 'automatic' : 'explicit']), $currentEscapes)),
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
                'pipeline_operations' => $pipelineOperations,
                'protection' => $protection,
                'context_reason' => $contextReason,
                'search' => $search,
                'source' => $this->sourceExcerptBuilder->create($plan['path'], $plan['line'], $plan['expression']),
                ...$plan,
                'current_escapes' => $currentEscapes,
                'value_contracts' => $valueContracts,
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
            $assessment = $this->findingAssessor->assessDiagnostic($diagnostic['code']);
            $ownership = $this->classifyOwnership($diagnostic['path']);
            $entries[$diagnostic['template']][] = [
                'type' => 'diagnostic',
                'assessment' => $assessment,
                'ownership' => $ownership,
                'search' => strtolower(implode(' ', [$diagnostic['template'], $diagnostic['line'], $ownership, $diagnostic['code'], $diagnostic['message'], $assessment['label'], $assessment['guidance']])),
                'source' => $this->sourceExcerptBuilder->create($diagnostic['path'], $diagnostic['line'], null),
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
                'source_uri' => null === $path ? null : $this->sourceExcerptBuilder->createFileUri($path),
                'findings' => $templateEntries,
            ];
        }

        $unsupported = [];
        foreach ($unsupportedNodes as $message => $count) {
            $unsupported[] = ['message' => $message, 'count' => $count];
        }

        return [
            'generated_at' => gmdate('Y-m-d H:i:s').' UTC',
            'summary' => $summary,
            'assessment_counts' => $assessmentCounts,
            'view_counts' => $viewCounts,
            'ownership_counts' => $ownershipCounts,
            'operations' => $this->describeOperations(array_keys($operations)),
            'navigation' => $this->buildNavigation($navigationEntries),
            'templates' => $templates,
            'unsupported_nodes' => $unsupported,
        ];
    }

    /**
     * @param list<string> $operations
     *
     * @return list<array{code: string, label: string, description: string}>
     */
    private function describeOperations(array $operations): array
    {
        return array_map($this->describeOperation(...), $operations);
    }

    /**
     * @return array{code: string, label: string, description: string}
     */
    private function describeOperation(string $operation): array
    {
        [$label, $description] = match ($operation) {
            'HtmlText' => ['Escape HTML text', 'Prevents dynamic text from being interpreted as HTML markup.'],
            'HtmlAttribute' => ['Protect a quoted HTML attribute', 'Prevents the value from ending the quoted attribute.'],
            'HtmlAttributeUnquoted' => ['Protect an unquoted HTML attribute', 'Prevents the value from ending or splitting the unquoted attribute.'],
            'HtmlRcdata' => ['Escape HTML RCDATA', 'Prevents the value from closing an element such as textarea or title.'],
            'JavaScriptValue' => ['Serialize a JavaScript value', 'Produces a syntax-safe JavaScript value without allowing code injection.'],
            'JavaScriptString' => ['Escape a JavaScript string', 'Prevents the value from ending the quoted JavaScript string.'],
            'JavaScriptTemplateString' => ['Escape a JavaScript template string', 'Prevents the value from ending the template string or starting an interpolation.'],
            'JavaScriptRegExp' => ['Escape a JavaScript regular expression', 'Prevents the value from changing the regular expression or following JavaScript syntax.'],
            'CssValue' => ['Escape a CSS value', 'Keeps the value inside the current CSS declaration value.'],
            'CssString' => ['Escape a CSS string', 'Prevents the value from ending the quoted CSS string.'],
            'MetaRefreshDelay' => ['Validate the refresh delay', 'Restricts the value to a valid meta refresh delay.'],
            'SrcsetFilter' => ['Validate a srcset value', 'Validates URL candidates and descriptors in the srcset value.'],
            'UrlSchemeFilter' => ['Allow only safe URL schemes', 'Rejects dangerous schemes such as javascript in a complete URL.'],
            'UrlNormalize' => ['Encode a complete URL', 'Encodes unsafe URL characters while preserving the complete URL structure.'],
            'UrlPath' => ['Encode a URL path component', 'Prevents the value from changing the surrounding URL path structure.'],
            'UrlQuery' => ['Encode a query or fragment component', 'Prevents the value from changing the surrounding query or fragment structure.'],
            default => [$operation, 'Applies the inferred contextual protection.'],
        };

        return ['code' => $operation, 'label' => $label, 'description' => $description];
    }

    /**
     * @param array{strategy: string, scope: 'whole'|'nested', expression: string, automatic: bool} $escape
     *
     * @return array{strategy: string, scope: 'whole'|'nested', expression: string, automatic: bool, label: string}
     */
    private function describeCurrentEscape(array $escape): array
    {
        $label = match ($escape['strategy']) {
            'html' => 'HTML escaping',
            'html_attr' => 'HTML attribute escaping',
            'html_attr_relaxed' => 'Relaxed HTML attribute escaping',
            'js' => 'JavaScript string escaping',
            'js_string' => 'JavaScript string escaping',
            'js_template' => 'JavaScript template string escaping',
            'js_regexp' => 'JavaScript regular expression escaping',
            'css' => 'CSS string escaping',
            'css_string' => 'CSS string escaping',
            'url' => 'URL component encoding',
            default => 'Dynamic or custom escaping',
        };

        return [...$escape, 'label' => $label];
    }

    /**
     * @param array{expression: string, implementation: string, content_type: string, source: string} $contract
     *
     * @return array{expression: string, implementation: string, content_type: string, source: string, result_label: string, meaning: string, effect: string}
     */
    private function describeValueContract(array $contract): array
    {
        [$meaning, $effect] = match ($contract['content_type']) {
            'Url' => [
                'The result represents an entire URL value, not an individual path, query, or fragment component.',
                'The result is analyzed as a complete URL rather than plain text. At a URL start, no scheme filter is added. The inferred contextual pipeline still includes any encoding needed by the surrounding context.',
            ],
            'Html' => [
                'The result is trusted as an HTML fragment rather than plain text.',
                'HTML text escaping is not added. The inferred contextual pipeline still includes any protection needed when the fragment is embedded in another context.',
            ],
            default => [
                \sprintf('The result carries the %s semantic content type rather than plain text.', $this->getContentTypeLabel($contract['content_type'])),
                'The inferred contextual pipeline already accounts for this contract and still includes any operations needed by the surrounding output context.',
            ],
        };

        return [
            ...$contract,
            'result_label' => $this->getContentTypeLabel($contract['content_type']),
            'meaning' => $meaning,
            'effect' => $effect,
        ];
    }

    private function getContentTypeLabel(string $contentType): string
    {
        return match ($contentType) {
            'PlainText' => 'Plain text',
            'TrustedInnermost' => 'Trusted innermost content',
            'Html' => 'HTML fragment',
            'HtmlAttributeList' => 'HTML attribute list',
            'HtmlAttribute' => 'Quoted HTML attribute value',
            'HtmlAttributeUnquoted' => 'Unquoted HTML attribute value',
            'HtmlRcdata' => 'HTML RCDATA',
            'JavaScriptExpression' => 'JavaScript expression',
            'JavaScriptString' => 'JavaScript string',
            'JavaScriptTemplateString' => 'JavaScript template string',
            'JavaScriptRegExp' => 'JavaScript regular expression',
            'Css' => 'CSS',
            'CssString' => 'CSS string',
            'Url' => 'Complete URL',
            'UrlComponent' => 'URL component',
            'Srcset' => 'srcset value',
            default => $contentType,
        };
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
}
