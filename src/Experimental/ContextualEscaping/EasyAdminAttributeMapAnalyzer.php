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

use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\ForNode;

/**
 * @internal
 *
 * @experimental
 */
final class EasyAdminAttributeMapAnalyzer implements ContextualEscapingAttributeMapAnalyzerInterface
{
    public function __construct(
        private HtmlAttributeMapLoopShapeAnalyzer $shapeAnalyzer,
    ) {
    }

    public function analyze(ForNode $node, ?string $blockName): ?ContextualEscapingAttributeMapAnalysis
    {
        $template = $node->getTemplateName() ?? '';
        if (!preg_match('{^@!?EasyAdmin/}', $template)) {
            return null;
        }

        $sequence = $node->getNode('seq');
        if (!$sequence instanceof GetAttrExpression || !$sequence->getNode('attribute') instanceof ConstantExpression || 'htmlAttributes' !== $sequence->getNode('attribute')->getAttribute('value')) {
            return null;
        }
        $key = $node->getNode('key_target');
        if (!$key->hasAttribute('name') || !\is_string($keyName = $key->getAttribute('name')) || !$this->shapeAnalyzer->supports($node, $keyName)) {
            return null;
        }

        return new ContextualEscapingAttributeMapAnalysis('EasyAdmin trusted attribute map');
    }
}
