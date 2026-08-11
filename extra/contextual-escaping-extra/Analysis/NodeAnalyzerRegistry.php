<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Analysis;

use Twig\Node\Node;

/**
 * @internal
 *
 * @experimental
 */
final class NodeAnalyzerRegistry
{
    /** @var list<NodeAnalyzerInterface> */
    private array $analyzers;

    /**
     * @param iterable<NodeAnalyzerInterface> $analyzers
     */
    public function __construct(iterable $analyzers)
    {
        $this->analyzers = \is_array($analyzers) ? array_values($analyzers) : iterator_to_array($analyzers, false);
    }

    public function classify(Node $node): ?NodeType
    {
        foreach ($this->analyzers as $analyzer) {
            if (null !== $type = $analyzer->classify($node)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array<string, ContentTypeSet>
     */
    public function getVariableContentTypes(Node $node): array
    {
        foreach ($this->analyzers as $analyzer) {
            if (!$analyzer instanceof VariableNodeAnalyzerInterface) {
                continue;
            }
            if ([] !== $contentTypes = $analyzer->getVariableContentTypes($node)) {
                return $contentTypes;
            }
        }

        return [];
    }
}
