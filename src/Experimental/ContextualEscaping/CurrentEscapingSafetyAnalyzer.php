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

use Twig\Environment;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\OperatorEscapeInterface;
use Twig\NodeTraverser;
use Twig\NodeVisitor\SafeAnalysisNodeVisitor;

/**
 * @internal
 *
 * @experimental
 */
final class CurrentEscapingSafetyAnalyzer
{
    /** @var array<int, list<array{expression: AbstractExpression, safe: list<string>, constant_output: bool}>> */
    private array $analyses = [];

    public function __construct(
        private Environment $environment,
    ) {
    }

    /**
     * @return array{safe: list<string>, constant_output: bool}
     */
    public function analyze(AbstractExpression $expression): array
    {
        $id = spl_object_id($expression);
        foreach ($this->analyses[$id] ?? [] as $analysis) {
            if ($analysis['expression'] === $expression) {
                return ['safe' => $analysis['safe'], 'constant_output' => $analysis['constant_output']];
            }
        }

        $constantOutput = method_exists(SafeAnalysisNodeVisitor::class, 'hasConstantOutput') ? SafeAnalysisNodeVisitor::hasConstantOutput($expression) : $this->hasConstantOutput($expression);
        if ($constantOutput) {
            $analysis = ['expression' => $expression, 'safe' => ['all'], 'constant_output' => true];
        } else {
            $visitor = new SafeAnalysisNodeVisitor();
            (new NodeTraverser($this->environment, [$visitor]))->traverse($expression);
            $analysis = [
                'expression' => $expression,
                'safe' => array_values($visitor->getSafe($expression)),
                'constant_output' => false,
            ];
        }
        $this->analyses[$id][] = $analysis;

        return ['safe' => $analysis['safe'], 'constant_output' => $analysis['constant_output']];
    }

    private function hasConstantOutput(AbstractExpression $expression): bool
    {
        if ($expression instanceof ConstantExpression) {
            return true;
        }
        if (!$expression instanceof OperatorEscapeInterface) {
            return false;
        }

        foreach ($expression->getOperandNamesToEscape() as $name) {
            $operand = $expression->getNode($name);
            if (!$operand instanceof AbstractExpression || !$this->hasConstantOutput($operand)) {
                return false;
            }
        }

        return true;
    }
}
