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

/**
 * @internal
 *
 * @experimental
 */
final class FiniteStaticValueSet
{
    public const MAX_VALUES = 256;

    /**
     * @param non-empty-list<mixed>  $values
     * @param non-empty-list<string> $provenance
     */
    public function __construct(
        private array $values,
        private array $provenance,
    ) {
    }

    /**
     * @return non-empty-list<mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * @return non-empty-list<string>
     */
    public function getProvenance(): array
    {
        return $this->provenance;
    }

    public function withProvenance(string $step): self
    {
        if ($step === $this->provenance[0]) {
            return $this;
        }

        return new self($this->values, [$step, ...$this->provenance]);
    }

    public function merge(self $other): ?self
    {
        $values = [];
        foreach ([...$this->values, ...$other->values] as $value) {
            $values[serialize($value)] = $value;
            if (self::MAX_VALUES < \count($values)) {
                return null;
            }
        }
        $provenance = $this->provenance;
        foreach ($other->provenance as $step) {
            if (!\in_array($step, $provenance, true)) {
                $provenance[] = $step;
            }
        }

        return new self(array_values($values), $provenance);
    }
}
