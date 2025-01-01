<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\NodeVisitor;

use Twig\Environment;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Node;
use Twig\Node\TypesNode;
use Twig\Template;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @internal
 */
final class VariableOptimizerNodeVisitor implements NodeVisitorInterface
{
    private array $types = [];

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof TypesNode) {
            $this->types = array_merge($this->types, $node->getAttribute('mapping'));
        }
        if ($node instanceof NameExpression && isset($this->types[$node->getAttribute('name')])) {
            $node->setAttribute('always_defined', true);
        }
        if (
            $node instanceof GetAttrExpression
            && $node->getNode('node') instanceof NameExpression
            && isset($this->types[$node->getNode('node')->getAttribute('name')])
        ) {
            if ('array' === $this->types[$node->getNode('node')->getAttribute('name')]['type']) {
                $node->setAttribute('ignore_strict_check', true);
                $node->setAttribute('type', Template::ARRAY_CALL);
                $node->setAttribute('is_typed', true);
            }
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        return $node;
    }

    public function getPriority(): int
    {
        return 255;
    }
}
