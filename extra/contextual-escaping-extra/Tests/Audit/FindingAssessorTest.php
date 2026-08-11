<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Twig\Extra\ContextualEscaping\Audit\FindingAssessor;

final class FindingAssessorTest extends TestCase
{
    public function testTreatsEquivalentOuterProtectionAsProtectedToday(): void
    {
        $assessment = (new FindingAssessor())->assessPlan([
            'operations' => ['HtmlAttribute'],
            'current' => 'html',
            'correct' => false,
        ]);

        $this->assertSame('partial', $assessment['status']);
        $this->assertSame('Protected today', $assessment['label']);
        $this->assertSame(['HtmlAttribute'], $assessment['covered_operations']);
        $this->assertSame([], $assessment['missing_operations']);
        $this->assertSame(['no-urgent'], $assessment['views']);
    }

    public function testTreatsMissingInnerProtectionAsUnsafeToday(): void
    {
        $assessment = (new FindingAssessor())->assessPlan([
            'operations' => ['UrlSchemeFilter', 'UrlNormalize', 'HtmlAttribute'],
            'current' => 'html',
            'correct' => false,
        ]);

        $this->assertSame('unsafe', $assessment['status']);
        $this->assertSame('Unsafe today', $assessment['label']);
        $this->assertSame(['HtmlAttribute'], $assessment['covered_operations']);
        $this->assertSame(['UrlSchemeFilter', 'UrlNormalize'], $assessment['missing_operations']);
        $this->assertSame(['action', 'future'], $assessment['views']);
    }
}
