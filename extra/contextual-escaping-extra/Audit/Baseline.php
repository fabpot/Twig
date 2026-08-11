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
final class Baseline
{
    public function __construct(
        private string $path,
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
     *
     * @return array{schema: 1, findings: list<array<string, mixed>>}
     */
    public function create(array $plans, array $diagnostics, array $unsupportedNodes): array
    {
        $findings = [];
        foreach ($plans as $plan) {
            if ($plan['correct'] || !$plan['operations']) {
                continue;
            }
            $finding = [
                'type' => 'plan',
                'template' => $plan['template'],
                'line' => $plan['line'],
                'operations' => $plan['operations'],
                'current' => $plan['current'],
                'expression' => $plan['expression'],
            ];
            $findings[] = ['id' => $this->createId($finding), ...$finding];
        }
        foreach ($diagnostics as $diagnostic) {
            $finding = [
                'type' => 'diagnostic',
                'template' => $diagnostic['template'],
                'line' => $diagnostic['line'],
                'code' => $diagnostic['code'],
                'message' => $diagnostic['message'],
            ];
            $findings[] = ['id' => $this->createId($finding), ...$finding];
        }
        foreach ($unsupportedNodes as $message => $count) {
            $finding = [
                'type' => 'unsupported_node',
                'message' => $message,
                'count' => $count,
            ];
            $findings[] = ['id' => $this->createId(['type' => $finding['type'], 'message' => $message]), ...$finding];
        }
        usort($findings, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return ['schema' => 1, 'findings' => $findings];
    }

    /**
     * @param array{schema: 1, findings: list<array<string, mixed>>} $report
     */
    public function write(array $report): void
    {
        $directory = \dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unable to create the contextual escaping JSON report directory "%s".', $directory));
        }
        if (false === file_put_contents($this->path, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n")) {
            throw new \RuntimeException(\sprintf('Unable to write the contextual escaping JSON report "%s".', $this->path));
        }
    }

    /**
     * @param array{schema: 1, findings: list<array<string, mixed>>} $report
     *
     * @return array{new: int, resolved: int, unchanged: int}
     */
    public function compare(string $baselinePath, array $report): array
    {
        if (!is_file($baselinePath) || false === $contents = file_get_contents($baselinePath)) {
            throw new \RuntimeException(\sprintf('Unable to read the contextual escaping baseline "%s".', $baselinePath));
        }
        $baseline = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($baseline) || 1 !== ($baseline['schema'] ?? null) || !\is_array($baseline['findings'] ?? null)) {
            throw new \RuntimeException(\sprintf('The contextual escaping baseline "%s" has an unsupported format.', $baselinePath));
        }

        $baselineIds = [];
        foreach ($baseline['findings'] as $finding) {
            if (!\is_array($finding) || !\is_string($finding['id'] ?? null)) {
                throw new \RuntimeException(\sprintf('The contextual escaping baseline "%s" has an unsupported finding.', $baselinePath));
            }
            $baselineIds[$finding['id']] = true;
        }
        $currentIds = array_fill_keys(array_column($report['findings'], 'id'), true);

        return [
            'new' => \count(array_diff_key($currentIds, $baselineIds)),
            'resolved' => \count(array_diff_key($baselineIds, $currentIds)),
            'unchanged' => \count(array_intersect_key($currentIds, $baselineIds)),
        ];
    }

    /**
     * @param array<string, mixed> $finding
     */
    private function createId(array $finding): string
    {
        return hash('sha256', json_encode($finding, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }
}
