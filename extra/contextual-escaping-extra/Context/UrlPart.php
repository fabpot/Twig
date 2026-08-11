<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Context;

/**
 * @internal
 *
 * @experimental
 */
enum UrlPart
{
    case None;
    case Start;
    case Path;
    case QueryOrFragment;
    case UnsafeScheme;
    case Unknown;

    public function describe(): string
    {
        return match ($this) {
            self::Start => 'URL start',
            self::Path => 'URL path',
            self::QueryOrFragment => 'URL query or fragment',
            self::UnsafeScheme => 'executable-scheme URL',
            self::None, self::Unknown => 'ambiguous URL',
        };
    }

    public function consume(string $character): self
    {
        if ('?' === $character || '#' === $character) {
            return self::QueryOrFragment;
        }
        if ('&' === $character && self::QueryOrFragment !== $this) {
            return self::Unknown;
        }
        if (self::Start === $this) {
            return self::Path;
        }

        return $this;
    }
}
