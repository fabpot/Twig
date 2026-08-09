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

use Twig\Node\Expression\FunctionExpression;

/**
 * @internal
 *
 * @experimental
 */
interface ContextualEscapingCallableAnalyzerInterface
{
    public function analyze(FunctionExpression $expression): ?ContextualEscapingCallableAnalysis;
}
