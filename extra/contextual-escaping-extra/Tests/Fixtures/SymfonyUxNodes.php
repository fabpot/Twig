<?php

namespace Symfony\UX\TwigComponent\Twig;

use Twig\Attribute\YieldReady;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Node;
use Twig\Node\NodeOutputInterface;

#[YieldReady]
final class ComponentNode extends Node implements NodeOutputInterface
{
    public function __construct(int $line, bool $valid = true)
    {
        parent::__construct([], $valid ? [
            'only' => false,
            'embedded_template' => '__embedded__',
            'embedded_index' => 0,
            'component' => 'Test',
        ] : [], $line);
    }
}

#[YieldReady]
class PropsNode extends Node
{
    public function __construct(int $line, array $values = [])
    {
        foreach ($values as $value) {
            if (!$value instanceof AbstractExpression) {
                throw new \LogicException('Prop values must be expressions.');
            }
        }

        parent::__construct($values, ['names' => array_keys($values)], $line);
    }
}
