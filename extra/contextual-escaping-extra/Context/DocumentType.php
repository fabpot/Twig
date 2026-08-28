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
enum DocumentType
{
    case Html;
    case JavaScript;
    case Css;

    public static function fromTemplateName(?string $name): ?self
    {
        if (null === $name) {
            return null;
        }

        return match (true) {
            str_ends_with($name, '.html.twig') => self::Html,
            str_ends_with($name, '.js.twig') => self::JavaScript,
            str_ends_with($name, '.css.twig') => self::Css,
            default => null,
        };
    }
}
