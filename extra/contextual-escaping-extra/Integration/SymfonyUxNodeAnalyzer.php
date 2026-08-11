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

use Twig\Extra\ContextualEscaping\Analysis\ContentType;
use Twig\Extra\ContextualEscaping\Analysis\ContentTypeSet;
use Twig\Extra\ContextualEscaping\Analysis\NodeType;
use Twig\Extra\ContextualEscaping\Analysis\VariableNodeAnalyzerInterface;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Node;

/**
 * @internal
 *
 * @experimental
 */
final class SymfonyUxNodeAnalyzer implements VariableNodeAnalyzerInterface
{
    private const COMPONENT_NODE = 'Symfony\\UX\\TwigComponent\\Twig\\ComponentNode';
    private const PROPS_NODE = 'Symfony\\UX\\TwigComponent\\Twig\\PropsNode';

    public function classify(Node $node): ?NodeType
    {
        return match ($node::class) {
            self::COMPONENT_NODE => $this->isSupportedComponentNode($node) ? NodeType::HtmlFragment : null,
            self::PROPS_NODE => $this->isSupportedPropsNode($node) ? NodeType::ContextPreserving : null,
            default => null,
        };
    }

    public function getVariableContentTypes(Node $node): array
    {
        if (self::PROPS_NODE !== $node::class || !$this->isSupportedPropsNode($node)) {
            return [];
        }

        return ['attributes' => new ContentTypeSet([ContentType::HtmlAttributeList])];
    }

    private function isSupportedComponentNode(Node $node): bool
    {
        foreach (['only', 'embedded_template', 'embedded_index', 'component'] as $name) {
            if (!$node->hasAttribute($name)) {
                return false;
            }
        }
        if (!\is_bool($node->getAttribute('only')) || !\is_string($node->getAttribute('embedded_template')) || !\is_int($node->getAttribute('embedded_index')) || !\is_string($node->getAttribute('component'))) {
            return false;
        }

        foreach ($node as $name => $child) {
            if ('props' !== $name || !$child instanceof AbstractExpression) {
                return false;
            }
        }

        return true;
    }

    private function isSupportedPropsNode(Node $node): bool
    {
        if (!$node->hasAttribute('names') || !\is_array($names = $node->getAttribute('names'))) {
            return false;
        }
        foreach ($names as $name) {
            if (!\is_string($name)) {
                return false;
            }
        }
        foreach ($node as $child) {
            if (!$child instanceof AbstractExpression) {
                return false;
            }
        }

        return true;
    }
}
