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

use Twig\Node\ForNode;

/**
 * @internal
 *
 * @experimental
 */
final class ContextualEscapingAttributeMapAnalyzerRegistry
{
    /** @var list<ContextualEscapingAttributeMapAnalyzerInterface> */
    private array $analyzers;

    /**
     * @param iterable<ContextualEscapingAttributeMapAnalyzerInterface> $analyzers
     */
    public function __construct(iterable $analyzers)
    {
        $this->analyzers = \is_array($analyzers) ? array_values($analyzers) : iterator_to_array($analyzers, false);
    }

    public function analyze(ForNode $node, ?string $blockName): ?ContextualEscapingAttributeMapAnalysis
    {
        foreach ($this->analyzers as $analyzer) {
            if (null !== $analysis = $analyzer->analyze($node, $blockName)) {
                return $analysis;
            }
        }

        return null;
    }
}
