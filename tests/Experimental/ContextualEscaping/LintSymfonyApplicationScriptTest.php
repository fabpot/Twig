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
            'contextual.html.twig:1 [EscapePlan] UrlSchemeFilter -> UrlNormalize -> HtmlAttribute [Current: html, incorrect]: {{ path }}',
            "contextual.html.twig:4 [EscapePlan] UrlSchemeFilter -> UrlNormalize -> HtmlAttribute [Current: html, incorrect]: {{ {'path': path, 'closing': '}}'}.path }}",
            'correct-attribute.html.twig:1 [EscapePlan] HtmlAttribute [Current: html_attr, correct]: {{ value }}',
            'correct-javascript.html.twig:1 [EscapePlan] JavaScriptString [Current: js, correct]: {{ value }}',
            "incorrect-explicit.html.twig:1 [EscapePlan] HtmlAttribute [Current: html, incorrect]: {{ value|e('html') }}",
            'invalid.html.twig:1 [UnsupportedOutputContext] Output expressions in CSS property-name contexts are not supported.',
            'unsafe-text.html.twig:1 [EscapePlan] HtmlText [Current: none, incorrect]: {{ value }}',
            '[UnsupportedNode] 2 occurrences: The "App\\UnsupportedNode" node has no contextual escaping analyzer.',
            'Analyzed 8 templates and 7 output sites; found 6 contextual escape plans (2 correct, 4 incorrect) and 3 diagnostics.',
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
