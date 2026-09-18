<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Tests;

use PHPUnit\Framework\TestCase;
use Twig\Markup;

class MarkupTest extends TestCase
{
    public function testContentIsSafeForAllStrategiesByDefault(): void
    {
        $this->assertSame(['all'], (new Markup('<br />', 'UTF-8'))->getSafeStrategies());
    }

    public function testContentIsSafeForTheGivenStrategies(): void
    {
        $this->assertSame(['html', 'js'], (new Markup('<br />', 'UTF-8', ['html', 'js']))->getSafeStrategies());
    }
}
