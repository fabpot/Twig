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

use Twig\Node\Expression\FunctionExpression;

/**
 * @internal
 *
 * @experimental
 */
interface CallableAnalyzerInterface
{
    public function analyze(FunctionExpression $expression): ?CallableAnalysis;
}
