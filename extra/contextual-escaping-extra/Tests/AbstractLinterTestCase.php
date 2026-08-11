<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Tests;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extra\ContextualEscaping\Analysis\AnalysisResult;
use Twig\Extra\ContextualEscaping\Analysis\DiagnosticCode;
use Twig\Extra\ContextualEscaping\Analysis\EscapeOperation;
use Twig\Extra\ContextualEscaping\Linter;
use Twig\Loader\ArrayLoader;
use Twig\Source;

abstract class AbstractLinterTestCase extends TestCase
{
    protected function lint(string $template, string $name = 'index.html.twig', bool $force = false): AnalysisResult
    {
        return $this->createLinter(new Environment(new ArrayLoader(), ['optimizations' => 0]))->lint(new Source($template, $name), $force);
    }

    /**
     * @param array<string, string> $templates
     */
    protected function lintTemplates(array $templates, string $name): AnalysisResult
    {
        $environment = new Environment(new ArrayLoader($templates), ['optimizations' => 0]);

        return $this->createLinter($environment)->lintTemplate($name);
    }

    protected function createLinter(Environment $environment): Linter
    {
        return Linter::create($environment);
    }

    /**
     * @return list<DiagnosticCode>
     */
    protected function getDiagnosticCodes(AnalysisResult $result): array
    {
        return array_map(static fn ($diagnostic) => $diagnostic->getCode(), $result->getDiagnostics());
    }

    /**
     * @return list<list<EscapeOperation>>
     */
    protected function getPlans(AnalysisResult $result): array
    {
        return array_map(static fn ($inferredEscape) => $inferredEscape->getPlan()->getOperations(), $result->getInferredEscapes());
    }
}
