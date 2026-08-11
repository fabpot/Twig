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
        private ReportDataBuilder $dataBuilder,
        private string $path,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @param list<array{template: string, path: string|null, line: int, operations: list<string>, context: string, current: string, correct: bool, expression: string|null, plain_variable: bool, current_safe: list<string>, current_escapes: list<array{strategy: string, scope: 'whole'|'nested', expression: string, automatic: bool}>, provenance: list<string>, static_output_count: int, value_contracts: list<array{expression: string, implementation: string, content_type: string, source: string}>}> $plans
     * @param list<array{template: string, path: string|null, line: int, code: string, message: string}>                                                                                                                                                                                                                                                                                                                                                                                                          $diagnostics
     * @param array<string, int>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  $unsupportedNodes
     * @param array{templates: int, output_sites: int, correct_plans: int, incorrect_plans: int, diagnostics: int}                                                                                                                                                                                                                                                                                                                                                                                                $summary
     */
    public function write(array $plans, array $diagnostics, array $unsupportedNodes, array $summary): void
    {
        $html = $this->twig->render('report.html.twig', $this->dataBuilder->build($plans, $diagnostics, $unsupportedNodes, $summary));

        $directory = \dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unable to create the HTML report directory "%s".', $directory));
        }
        if (false === file_put_contents($this->path, $html)) {
            throw new \RuntimeException(\sprintf('Unable to write the HTML report to "%s".', $this->path));
        }
    }
}
