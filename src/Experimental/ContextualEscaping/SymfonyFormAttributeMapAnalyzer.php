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

use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\ForNode;

/**
 * @internal
 *
 * @experimental
 */
final class SymfonyFormAttributeMapAnalyzer implements ContextualEscapingAttributeMapAnalyzerInterface
{
    public function __construct(
        private HtmlAttributeMapLoopShapeAnalyzer $shapeAnalyzer,
    ) {
    }

    public function analyze(ForNode $node, ?string $blockName): ?ContextualEscapingAttributeMapAnalysis
    {
        $sequence = $node->getNode('seq');
        $key = $node->getNode('key_target');
        $value = $node->getNode('value_target');
        if (!$sequence instanceof ContextVariable || 'attr' !== $sequence->getAttribute('name') || !$key->hasAttribute('name') || 'attrname' !== $key->getAttribute('name') || !$value->hasAttribute('name') || 'attrvalue' !== $value->getAttribute('name')) {
            return null;
        }

        if (!\in_array($blockName, ['attributes', 'widget_attributes'], true)) {
            return null;
        }
        if (!$this->shapeAnalyzer->supports($node, 'attrname')) {
            return null;
        }

        return new ContextualEscapingAttributeMapAnalysis('Symfony Form trusted attribute map');
    }
}
