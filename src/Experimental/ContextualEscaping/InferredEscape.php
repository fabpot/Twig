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

use Twig\Node\PrintNode;

/**
 * @experimental
 */
final class InferredEscape
{
    public function __construct(
        private PrintNode $node,
        private EscapePlan $plan,
    ) {
    }

    public function getNode(): PrintNode
    {
        return $this->node;
    }

    public function getPlan(): EscapePlan
    {
        return $this->plan;
    }
}
