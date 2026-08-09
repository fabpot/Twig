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

use Twig\Node\Expression\FunctionExpression;

/**
 * @internal
 *
 * @experimental
 */
final class ContextualEscapingCallableAnalyzerRegistry
{
    /**
     * @param iterable<ContextualEscapingCallableAnalyzerInterface> $analyzers
     */
    public function __construct(
        private iterable $analyzers,
    ) {
    }

    public function analyze(FunctionExpression $expression): ?ContextualEscapingCallableAnalysis
    {
        foreach ($this->analyzers as $analyzer) {
            if (null !== $analysis = $analyzer->analyze($expression)) {
                return $analysis;
            }
        }

        return null;
    }
}
