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
final class ValueContract
{
    public function __construct(
        private string $expression,
        private string $implementation,
        private ContentType $contentType,
        private string $source,
    ) {
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function getImplementation(): string
    {
        return $this->implementation;
    }

    public function getContentType(): ContentType
    {
        return $this->contentType;
    }

    public function getSource(): string
    {
        return $this->source;
    }
}
