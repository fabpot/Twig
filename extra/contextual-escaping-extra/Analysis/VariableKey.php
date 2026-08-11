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

use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\Expression\Variable\LocalVariable;

/**
 * Keys used to track per-variable inferences; the analyzer and the static
 * expression analyzer share the same maps, so they must agree on the keys.
 *
 * @internal
 *
 * @experimental
 */
final class VariableKey
{
    public static function fromVariable(ContextVariable|LocalVariable $variable): string
    {
        return $variable instanceof LocalVariable ? 'local:'.spl_object_id($variable) : self::fromContextName($variable->getAttribute('name'));
    }

    public static function fromContextName(string $name): string
    {
        return 'context:'.$name;
    }

    private function __construct()
    {
    }
}
