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

use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\Binary\ConcatBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\OperatorEscapeInterface;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\Expression\Variable\LocalVariable;
use Twig\Runtime\EscaperRuntime;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @internal
 *
 * @experimental
 */
final class StaticExpressionAnalyzer
{
    public function __construct(
        private Environment $environment,
    ) {
    }

    /**
     * @param array<string, FiniteStaticValueSet> $variables
     */
    public function analyze(AbstractExpression $expression, array $variables): ?FiniteStaticValueSet
    {
        if ($expression instanceof ConstantExpression && !$expression->isDefinedTestEnabled()) {
            return new FiniteStaticValueSet([$expression->getAttribute('value')], ['constant value']);
        }

        if ($expression instanceof ContextVariable || $expression instanceof LocalVariable) {
            return $variables[VariableKey::fromVariable($expression)] ?? null;
        }

        if ($expression instanceof ArrayExpression) {
            return $this->analyzeArray($expression, $variables);
        }

        if ($expression instanceof GetAttrExpression && !$expression->isDefinedTestEnabled()) {
            return $this->analyzeAttribute($expression, $variables);
        }

        if ($expression instanceof FunctionExpression) {
            return $this->analyzeFunction($expression, $variables);
        }

        if ($expression instanceof FilterExpression) {
            return $this->analyzeFilter($expression, $variables);
        }

        if ($expression instanceof ConcatBinary) {
            return $this->analyzeConcatenation($expression, $variables);
        }

        if ($expression instanceof OperatorEscapeInterface) {
            return $this->analyzeAlternativeOutputs($expression, $variables);
        }

        return null;
    }

    /**
     * @param array<string, FiniteStaticValueSet> $variables
     */
    private function analyzeArray(ArrayExpression $expression, array $variables): ?FiniteStaticValueSet
    {
        $arrays = [[]];
        foreach ($expression->getKeyValuePairs() as $pair) {
            $keys = $this->analyze($pair['key'], $variables);
            $values = $this->analyze($pair['value'], $variables);
            if (null === $keys || null === $values) {
                return null;
            }

            $next = [];
            foreach ($arrays as $array) {
                foreach ($keys->getValues() as $key) {
                    if (!\is_int($key) && !\is_string($key)) {
                        return null;
                    }
                    foreach ($values->getValues() as $value) {
                        $variant = $array;
                        $variant[$key] = $value;
                        $next[] = $variant;
                        if (FiniteStaticValueSet::MAX_VALUES < \count($next)) {
                            return null;
                        }
                    }
                }
            }
            $arrays = $next;
        }

        return $this->createValueSet($arrays, ['fixed local array']);
    }

    /**
     * @param array<string, FiniteStaticValueSet> $variables
     */
    private function analyzeAttribute(GetAttrExpression $expression, array $variables): ?FiniteStaticValueSet
    {
        $inputs = $this->analyze($expression->getNode('node'), $variables);
        $attributes = $this->analyze($expression->getNode('attribute'), $variables);
        if (null === $inputs || null === $attributes) {
            return null;
        }

        $values = [];
        foreach ($inputs->getValues() as $input) {
            if (!\is_array($input)) {
                return null;
            }
            foreach ($attributes->getValues() as $attribute) {
                if ((!\is_int($attribute) && !\is_string($attribute)) || !\array_key_exists($attribute, $input)) {
                    return null;
                }
                $values[] = $input[$attribute];
            }
        }

        return $this->createValueSet($values, $this->combineProvenance($this->describeExpression($expression), [$inputs, $attributes]));
    }

    /**
     * @param array<string, FiniteStaticValueSet> $variables
     */
    private function analyzeFunction(FunctionExpression $expression, array $variables): ?FiniteStaticValueSet
    {
        $function = $expression->getAttribute('twig_callable');
        if (!$function instanceof TwigFunction || 'random' !== $function->getName()) {
            return null;
        }

        $arguments = $expression->getNode('arguments');
        if (!\count($arguments)) {
            return null;
        }
        $inputs = $this->analyze($arguments->getNode(0), $variables);
        if (null === $inputs) {
            return null;
        }

        $values = [];
        foreach ($inputs->getValues() as $input) {
            if (!\is_array($input) || !$input) {
                return null;
            }
            array_push($values, ...array_values($input));
        }

        return $this->createValueSet($values, $this->combineProvenance($this->describeExpression($expression), [$inputs]));
    }

    /**
     * @param array<string, FiniteStaticValueSet> $variables
     */
    private function analyzeFilter(FilterExpression $expression, array $variables): ?FiniteStaticValueSet
    {
        $filter = $expression->getAttribute('twig_callable');
        if (!$filter instanceof TwigFilter) {
            return null;
        }

        $inputs = $this->analyze($expression->getNode('node'), $variables);
        if (null === $inputs) {
            return null;
        }

        if ('raw' === $filter->getName()) {
            return $inputs;
        }

        if (\in_array($filter->getName(), ['e', 'escape'], true)) {
            return $this->analyzeEscapeFilter($expression, $inputs);
        }

        if (\in_array($filter->getName(), ['first', 'last'], true)) {
            $values = [];
            foreach ($inputs->getValues() as $input) {
                if (!\is_array($input) || !$input) {
                    return null;
                }
                $values[] = 'first' === $filter->getName() ? reset($input) : end($input);
            }

            return $this->createValueSet($values, $this->combineProvenance($this->describeExpression($expression), [$inputs]));
        }

        if ('merge' === $filter->getName()) {
            $arguments = $expression->getNode('arguments');
            if (!\count($arguments) || null === $right = $this->analyze($arguments->getNode(0), $variables)) {
                return null;
            }
            $values = [];
            foreach ($inputs->getValues() as $leftValue) {
                foreach ($right->getValues() as $rightValue) {
                    if (!\is_array($leftValue) || !\is_array($rightValue)) {
                        return null;
                    }
                    $values[] = array_merge($leftValue, $rightValue);
                }
            }

            return $this->createValueSet($values, $this->combineProvenance($this->describeExpression($expression), [$inputs, $right]));
        }

        return null;
    }

    private function analyzeEscapeFilter(FilterExpression $expression, FiniteStaticValueSet $inputs): ?FiniteStaticValueSet
    {
        $arguments = $expression->getNode('arguments');
        $strategy = 'html';
        if (\count($arguments)) {
            $strategyNode = $arguments->getNode(0);
            if (!$strategyNode instanceof ConstantExpression || !\is_string($strategyNode->getAttribute('value'))) {
                return null;
            }
            $strategy = $strategyNode->getAttribute('value');
        }
        if (!\in_array($strategy, ['html', 'js', 'css', 'html_attr', 'html_attr_relaxed', 'url'], true)) {
            return null;
        }
        $automatic = $arguments->hasNode(2) && $arguments->getNode(2) instanceof ConstantExpression && true === $arguments->getNode(2)->getAttribute('value');
        $escaper = $this->environment->getRuntime(EscaperRuntime::class);
        $values = [];
        try {
            foreach ($inputs->getValues() as $input) {
                if (\is_array($input)) {
                    return null;
                }
                $values[] = $escaper->escape($input, $strategy, null, $automatic);
            }
        } catch (RuntimeError) {
            return null;
        }

        return $this->createValueSet($values, $inputs->getProvenance());
    }

    /**
     * @param array<string, FiniteStaticValueSet> $variables
     */
    private function analyzeConcatenation(ConcatBinary $expression, array $variables): ?FiniteStaticValueSet
    {
        $left = $this->analyze($expression->getNode('left'), $variables);
        $right = $this->analyze($expression->getNode('right'), $variables);
        if (null === $left || null === $right) {
            return null;
        }

        $values = [];
        foreach ($left->getValues() as $leftValue) {
            foreach ($right->getValues() as $rightValue) {
                $leftValue = StaticOutput::stringify($leftValue);
                $rightValue = StaticOutput::stringify($rightValue);
                if (null === $leftValue || null === $rightValue) {
                    return null;
                }
                $values[] = $leftValue.$rightValue;
            }
        }

        return $this->createValueSet($values, $this->combineProvenance('static concatenation', [$left, $right]));
    }

    /**
     * @param array<string, FiniteStaticValueSet> $variables
     */
    private function analyzeAlternativeOutputs(AbstractExpression&OperatorEscapeInterface $expression, array $variables): ?FiniteStaticValueSet
    {
        $analyses = [];
        $values = [];
        foreach ($expression->getOperandNamesToEscape() as $name) {
            $operand = $expression->getNode($name);
            if (!$operand instanceof AbstractExpression || null === $analysis = $this->analyze($operand, $variables)) {
                return null;
            }
            $analyses[] = $analysis;
            array_push($values, ...$analysis->getValues());
        }

        return $this->createValueSet($values, $this->combineProvenance('finite conditional branches', $analyses));
    }

    /**
     * @param list<FiniteStaticValueSet> $inputs
     *
     * @return non-empty-list<string>
     */
    private function combineProvenance(string $step, array $inputs): array
    {
        $provenance = [$step];
        foreach ($inputs as $input) {
            foreach ($input->getProvenance() as $inputStep) {
                if (!\in_array($inputStep, $provenance, true)) {
                    $provenance[] = $inputStep;
                }
            }
        }

        return $provenance;
    }

    /**
     * @param list<mixed>            $values
     * @param non-empty-list<string> $provenance
     */
    private function createValueSet(array $values, array $provenance): ?FiniteStaticValueSet
    {
        $unique = [];
        foreach ($values as $value) {
            $unique[serialize($value)] = $value;
            if (FiniteStaticValueSet::MAX_VALUES < \count($unique)) {
                return null;
            }
        }

        return $unique ? new FiniteStaticValueSet(array_values($unique), $provenance) : null;
    }

    private function describeExpression(AbstractExpression $expression): string
    {
        if ($expression instanceof ContextVariable) {
            return $expression->getAttribute('name');
        }
        if ($expression instanceof LocalVariable) {
            return 'local value';
        }
        if ($expression instanceof FunctionExpression && $expression->getAttribute('twig_callable') instanceof TwigFunction) {
            $arguments = $expression->getNode('arguments');
            $argument = \count($arguments) && $arguments->getNode(0) instanceof AbstractExpression ? $this->describeExpression($arguments->getNode(0)) : '';

            return $expression->getAttribute('twig_callable')->getName().'('.$argument.')';
        }
        if ($expression instanceof FilterExpression && $expression->getAttribute('twig_callable') instanceof TwigFilter) {
            return $this->describeExpression($expression->getNode('node')).'|'.$expression->getAttribute('twig_callable')->getName();
        }
        if ($expression instanceof GetAttrExpression) {
            $attribute = $expression->getNode('attribute');
            $attribute = $attribute instanceof ConstantExpression ? $attribute->getAttribute('value') : '?';

            return $this->describeExpression($expression->getNode('node')).'['.$attribute.']';
        }

        return 'static expression';
    }
}
