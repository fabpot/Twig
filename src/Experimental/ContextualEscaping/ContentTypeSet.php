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
final class ContentTypeSet
{
    /**
     * @param non-empty-list<ContentType> $types
     */
    public function __construct(
        private array $types,
    ) {
    }

    public function contains(ContentType $type): bool
    {
        return \in_array($type, $this->types, true);
    }

    public function isPlainText(): bool
    {
        return [ContentType::PlainText] === $this->types;
    }

    public function intersect(self $other): self
    {
        $types = [];
        foreach ($this->types as $type) {
            if ($other->contains($type)) {
                $types[] = $type;
            }
        }

        return new self($types ?: [ContentType::PlainText]);
    }

    public function equals(self $other): bool
    {
        return $this->types === $other->types;
    }
}
