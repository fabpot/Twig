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

use Twig\Node\PrintNode;

/**
 * @experimental
 */
final class InferredEscape
{
    /**
     * @param list<string>        $provenance
     * @param list<string>        $staticOutputs
     * @param list<ValueContract> $valueContracts
     */
    public function __construct(
        private PrintNode $node,
        private EscapePlan $plan,
        private string $context = 'an unknown context',
        private array $provenance = [],
        private array $staticOutputs = [],
        private array $valueContracts = [],
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

    public function getContext(): string
    {
        return $this->context;
    }

    /**
     * @return list<string>
     */
    public function getProvenance(): array
    {
        return $this->provenance;
    }

    /**
     * @return list<string>
     */
    public function getStaticOutputs(): array
    {
        return $this->staticOutputs;
    }

    /**
     * @return list<ValueContract>
     */
    public function getValueContracts(): array
    {
        return $this->valueContracts;
    }
}
