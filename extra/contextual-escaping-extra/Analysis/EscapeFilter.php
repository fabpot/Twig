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

use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\TwigFilter;

/**
 * @internal
 *
 * @experimental
 */
final class EscapeFilter
{
    public static function matches(FilterExpression $expression): bool
    {
        $filter = $expression->getAttribute('twig_callable');

        return $filter instanceof TwigFilter && \in_array($filter->getName(), ['e', 'escape'], true);
    }

    /**
     * Returns the escaping strategy, "html" when omitted, or null when it is not a constant string.
     */
    public static function getConstantStrategy(FilterExpression $expression): ?string
    {
        $arguments = $expression->getNode('arguments');
        if (!\count($arguments)) {
            return 'html';
        }
        $strategy = $arguments->getNode(0);

        return $strategy instanceof ConstantExpression && \is_string($strategy->getAttribute('value')) ? $strategy->getAttribute('value') : null;
    }

    public static function isAutomatic(FilterExpression $expression): bool
    {
        $arguments = $expression->getNode('arguments');

        return $arguments->hasNode(2) && $arguments->getNode(2) instanceof ConstantExpression && true === $arguments->getNode(2)->getAttribute('value');
    }

    private function __construct()
    {
    }
}
