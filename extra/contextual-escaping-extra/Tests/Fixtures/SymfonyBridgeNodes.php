<?php

namespace Symfony\Bridge\Twig\Node;

use Twig\Attribute\YieldReady;
use Twig\Node\EmptyNode;
use Twig\Node\Node;
use Twig\Node\NodeOutputInterface;

#[YieldReady]
final class FormThemeNode extends Node
{
    public function __construct(int $line, bool $valid = true)
    {
        parent::__construct($valid ? ['form' => new EmptyNode(), 'resources' => new EmptyNode()] : [], $valid ? ['only' => false] : [], $line);
    }
}

#[YieldReady]
final class TransNode extends Node implements NodeOutputInterface
{
    public function __construct(int $line, bool $valid = true)
    {
        parent::__construct($valid ? ['body' => new EmptyNode()] : [], [], $line);
    }
}
