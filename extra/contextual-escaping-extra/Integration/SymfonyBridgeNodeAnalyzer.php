<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Integration;

use Twig\Extra\ContextualEscaping\Analysis\NodeAnalyzerInterface;
use Twig\Extra\ContextualEscaping\Analysis\NodeType;
use Twig\Node\Node;

/**
 * @internal
 *
 * @experimental
 */
final class SymfonyBridgeNodeAnalyzer implements NodeAnalyzerInterface
{
    private const FORM_THEME_NODE = 'Symfony\\Bridge\\Twig\\Node\\FormThemeNode';
    private const TRANS_NODE = 'Symfony\\Bridge\\Twig\\Node\\TransNode';

    public function classify(Node $node): ?NodeType
    {
        return match ($node::class) {
            self::FORM_THEME_NODE => $this->isSupportedFormThemeNode($node) ? NodeType::ContextPreserving : null,
            self::TRANS_NODE => $this->isSupportedTransNode($node) ? NodeType::PlainTextOutput : null,
            default => null,
        };
    }

    private function isSupportedFormThemeNode(Node $node): bool
    {
        if (!$node->hasNode('form') || !$node->hasNode('resources') || !$node->hasAttribute('only') || !\is_bool($node->getAttribute('only'))) {
            return false;
        }

        foreach ($node as $name => $child) {
            if (!\in_array($name, ['form', 'resources'], true) || !$child instanceof Node) {
                return false;
            }
        }

        return true;
    }

    private function isSupportedTransNode(Node $node): bool
    {
        if (!$node->hasNode('body')) {
            return false;
        }

        foreach ($node as $name => $child) {
            if (!\in_array($name, ['body', 'domain', 'count', 'vars', 'locale'], true) || !$child instanceof Node) {
                return false;
            }
        }

        return true;
    }
}
