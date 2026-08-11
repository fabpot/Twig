<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Tests\NodeVisitor;

use PHPUnit\Framework\TestCase;
use Twig\Node\Expression\Binary\ConcatBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\Ternary\ConditionalTernary;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\NodeVisitor\SafeAnalysisNodeVisitor;

class SafeAnalysisNodeVisitorTest extends TestCase
{
    public function testConstantExpressionHasConstantOutput(): void
    {
        $this->assertTrue(SafeAnalysisNodeVisitor::hasConstantOutput(new ConstantExpression('value', 1)));
    }

    public function testVariableHasNoConstantOutput(): void
    {
        $this->assertFalse(SafeAnalysisNodeVisitor::hasConstantOutput(new ContextVariable('value', 1)));
    }

    public function testTernaryWithConstantOperandsHasConstantOutput(): void
    {
        $ternary = new ConditionalTernary(new ContextVariable('condition', 1), new ConstantExpression('yes', 1), new ConstantExpression('no', 1), 1);

        $this->assertTrue(SafeAnalysisNodeVisitor::hasConstantOutput($ternary));
    }

    public function testTernaryWithAVariableOperandHasNoConstantOutput(): void
    {
        $ternary = new ConditionalTernary(new ContextVariable('condition', 1), new ConstantExpression('yes', 1), new ContextVariable('value', 1), 1);

        $this->assertFalse(SafeAnalysisNodeVisitor::hasConstantOutput($ternary));
    }

    public function testNonEscapingOperatorHasNoConstantOutput(): void
    {
        $concat = new ConcatBinary(new ConstantExpression('a', 1), new ConstantExpression('b', 1), 1);

        $this->assertFalse(SafeAnalysisNodeVisitor::hasConstantOutput($concat));
    }
}
