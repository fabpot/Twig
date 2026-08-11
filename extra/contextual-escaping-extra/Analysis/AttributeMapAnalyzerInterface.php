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

use Twig\Node\ForNode;

/**
 * @internal
 *
 * @experimental
 */
interface AttributeMapAnalyzerInterface
{
    public function analyze(ForNode $node, ?string $blockName): ?AttributeMapAnalysis;
}
