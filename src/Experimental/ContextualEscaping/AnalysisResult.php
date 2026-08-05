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

/**
 * @internal
 *
 * @experimental
 */
final class AnalysisResult
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /** @var list<InferredEscape> */
    private array $inferredEscapes = [];

    public function __construct(
        private bool $skipped = false,
    ) {
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function addDiagnostic(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /**
     * @return list<Diagnostic>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    public function hasErrors(): bool
    {
        return [] !== $this->diagnostics;
    }

    public function addInferredEscape(InferredEscape $inferredEscape): void
    {
        $this->inferredEscapes[] = $inferredEscape;
    }

    /**
     * @return list<InferredEscape>
     */
    public function getInferredEscapes(): array
    {
        return $this->inferredEscapes;
    }
}
