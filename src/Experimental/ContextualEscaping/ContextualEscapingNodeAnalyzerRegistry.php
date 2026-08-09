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

use Twig\Node\Node;

/**
 * @internal
 *
 * @experimental
 */
final class ContextualEscapingNodeAnalyzerRegistry
{
    /** @var list<ContextualEscapingNodeAnalyzerInterface> */
    private array $analyzers;

    /**
     * @param iterable<ContextualEscapingNodeAnalyzerInterface> $analyzers
     */
    public function __construct(iterable $analyzers)
    {
        $this->analyzers = \is_array($analyzers) ? array_values($analyzers) : iterator_to_array($analyzers, false);
    }

    public function classify(Node $node): ?ContextualEscapingNodeType
    {
        foreach ($this->analyzers as $analyzer) {
            if (null !== $type = $analyzer->classify($node)) {
                return $type;
            }
        }

        return null;
    }
}
