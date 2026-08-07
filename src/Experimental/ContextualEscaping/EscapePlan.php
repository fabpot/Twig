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
 * @experimental
 */
final class EscapePlan
{
    /**
     * @param list<EscapeOperation> $operations
     */
    public function __construct(
        private array $operations,
    ) {
    }

    /**
     * @return list<EscapeOperation>
     */
    public function getOperations(): array
    {
        return $this->operations;
    }
}
