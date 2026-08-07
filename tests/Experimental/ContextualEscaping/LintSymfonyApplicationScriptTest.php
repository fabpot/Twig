<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Tests\Experimental\ContextualEscaping;

use PHPUnit\Framework\TestCase;

final class LintSymfonyApplicationScriptTest extends TestCase
{
    public function testLintsTemplatesWithTheApplicationTwigEnvironment(): void
    {
        [$status, $output] = $this->runScript(__DIR__.'/Fixtures/symfony-app');

        $this->assertSame(1, $status);
        $this->assertSame([
            'invalid.html.twig:1 [UnsupportedOutputContext] Output expressions in CSS property-name contexts are not supported.',
            'Linted 2 templates; found 1 diagnostic.',
        ], $output);
    }

    public function testRequiresAnApplicationPath(): void
    {
        [$status, $output] = $this->runScript();

        $this->assertSame(2, $status);
        $this->assertSame(['Usage: php bin/lint_contextual_escaping.php /path/to/symfony-app'], $output);
    }

    /**
     * @return array{int, list<string>}
     */
    private function runScript(?string $projectDirectory = null): array
    {
        $command = escapeshellarg(\PHP_BINARY).' '.escapeshellarg(\dirname(__DIR__, 3).'/bin/lint_contextual_escaping.php');
        if (null !== $projectDirectory) {
            $command .= ' '.escapeshellarg($projectDirectory);
        }

        $output = [];
        exec($command.' 2>&1', $output, $status);

        return [$status, $output];
    }
}
