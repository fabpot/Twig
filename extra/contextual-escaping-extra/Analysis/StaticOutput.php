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
final class StaticOutput
{
    /**
     * Converts a static value into the string a template would print, or null
     * when the value has no printable representation.
     */
    public static function stringify(mixed $value): ?string
    {
        if (null === $value || false === $value) {
            return '';
        }
        if (true === $value) {
            return '1';
        }

        return \is_string($value) || \is_int($value) || \is_float($value) ? (string) $value : null;
    }

    private function __construct()
    {
    }
}
