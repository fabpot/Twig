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

use Twig\Node\AutoEscapeNode;
use Twig\Node\EmptyNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\OperatorEscapeInterface;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\ForLoopNode;
use Twig\Node\ForNode;
use Twig\Node\IfNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\PrintNode;
use Twig\Node\TextNode;

/**
 * @internal
 *
 * @experimental
 */
final class HtmlAttributeMapLoopShapeAnalyzer
{
    private const KEY = '{{#attribute-name#}}';
    private const VALUE = '{{#attribute-value#}}';

    public function supports(ForNode $node, string $keyName): bool
    {
        if ($node->hasNode('else') || null === $outputs = $this->render($node->getNode('body'), $keyName)) {
            return false;
        }

        $key = preg_quote(self::KEY, '/');
        $value = preg_quote(self::VALUE, '/');
        $attribute = $key.'=(?:"(?:'.$key.'|'.$value.')"|\'(?:'.$key.'|'.$value.')\')';
        foreach ($outputs as $output) {
            if (!preg_match('/^\s*(?:'.$attribute.')?\s*$/D', $output)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>|null
     */
    private function render(Node $node, string $keyName): ?array
    {
        if ($node instanceof EmptyNode || $node instanceof ForLoopNode) {
            return [''];
        }
        if ($node instanceof TextNode) {
            return [$node->getAttribute('data')];
        }
        if ($node instanceof PrintNode) {
            $expression = $node->getNode('expr');

            return $expression instanceof AbstractExpression ? $this->renderExpression($expression, $keyName) : null;
        }
        if ($node instanceof IfNode) {
            $outputs = [];
            $tests = $node->getNode('tests');
            for ($i = 1; $i < \count($tests); $i += 2) {
                if (!$tests->hasNode((string) $i) || null === $branch = $this->render($tests->getNode((string) $i), $keyName)) {
                    return null;
                }
                array_push($outputs, ...$branch);
            }
            if ($node->hasNode('else')) {
                if (null === $branch = $this->render($node->getNode('else'), $keyName)) {
                    return null;
                }
                array_push($outputs, ...$branch);
            } else {
                $outputs[] = '';
            }

            return array_values(array_unique($outputs));
        }
        if ($node instanceof AutoEscapeNode) {
            return $this->render($node->getNode('body'), $keyName);
        }
        if (!$node instanceof Nodes) {
            return null;
        }

        $outputs = [''];
        foreach ($node as $child) {
            if (null === $parts = $this->render($child, $keyName)) {
                return null;
            }
            $combined = [];
            foreach ($outputs as $output) {
                foreach ($parts as $part) {
                    $combined[$output.$part] = true;
                    if (64 < \count($combined)) {
                        return null;
                    }
                }
            }
            $outputs = array_keys($combined);
        }

        return $outputs;
    }

    /**
     * @return list<string>|null
     */
    private function renderExpression(AbstractExpression $expression, string $keyName): ?array
    {
        if ($expression instanceof ConstantExpression && !$expression->isDefinedTestEnabled()) {
            $output = StaticOutput::stringify($expression->getAttribute('value'));

            return null === $output ? null : [$output];
        }
        if ($expression instanceof OperatorEscapeInterface) {
            $outputs = [];
            foreach ($expression->getOperandNamesToEscape() as $name) {
                $operand = $expression->getNode($name);
                if (!$operand instanceof AbstractExpression || null === $operandOutputs = $this->renderExpression($operand, $keyName)) {
                    return null;
                }
                foreach ($operandOutputs as $output) {
                    $outputs[serialize($output)] = $output;
                }
            }

            return array_values($outputs);
        }
        if (!$this->isHtmlEscaped($expression)) {
            return null;
        }

        return [$this->isVariable($expression, $keyName) ? self::KEY : self::VALUE];
    }

    private function isVariable(AbstractExpression $expression, string $name): bool
    {
        while ($expression instanceof FilterExpression && $this->isEscapeFilter($expression)) {
            $input = $expression->getNode('node');
            if (!$input instanceof AbstractExpression) {
                return false;
            }
            $expression = $input;
        }

        return $expression instanceof ContextVariable && $name === $expression->getAttribute('name');
    }

    private function isHtmlEscaped(AbstractExpression $expression): bool
    {
        if ($expression instanceof ConstantExpression) {
            return true;
        }
        if ($expression instanceof FilterExpression && $this->isEscapeFilter($expression)) {
            return true;
        }
        if (!$expression instanceof OperatorEscapeInterface) {
            return false;
        }
        foreach ($expression->getOperandNamesToEscape() as $name) {
            $operand = $expression->getNode($name);
            if (!$operand instanceof AbstractExpression || !$this->isHtmlEscaped($operand)) {
                return false;
            }
        }

        return true;
    }

    private function isEscapeFilter(FilterExpression $expression): bool
    {
        return EscapeFilter::matches($expression) && \in_array(EscapeFilter::getConstantStrategy($expression), ['html', 'html_attr', 'html_attr_relaxed'], true);
    }
}
