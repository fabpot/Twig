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
        $projectDirectory = __DIR__.'/Fixtures/symfony-app';
        $report = $projectDirectory.'/var/contextual-escaping.html';

        try {
            [$status, $output] = $this->runScript($projectDirectory);

            $this->assertSame(1, $status);
            $this->assertSame([
                'contextual.html.twig:1 [EscapePlan] UrlSchemeFilter -> UrlNormalize -> HtmlAttribute [Current: html, incorrect]: {{ path }}',
                "contextual.html.twig:4 [EscapePlan] UrlSchemeFilter -> UrlNormalize -> HtmlAttribute [Current: html, incorrect]: {{ {'path': path, 'closing': '}}'}.path }}",
                'correct-attribute.html.twig:1 [EscapePlan] HtmlAttribute [Current: html_attr, correct]: {{ value }}',
                'correct-javascript.html.twig:1 [EscapePlan] JavaScriptString [Current: js, correct]: {{ value }}',
                '@Dependency/link.html.twig:1 [EscapePlan] UrlSchemeFilter -> UrlNormalize -> HtmlAttribute [Current: html, incorrect]: {{ dependency_url }}',
                "incorrect-explicit.html.twig:1 [EscapePlan] HtmlAttribute [Current: html, incorrect]: {{ value|e('html') }}",
                'invalid.html.twig:1 [UnsupportedOutputContext] Output expressions in CSS property-name contexts are not supported.',
                'legacy-safe.html.twig:1 [EscapePlan] HtmlText [Current: none, incorrect]: {{ legacy_safe() }}',
                "nested-escaping.html.twig:1 [EscapePlan] JavaScriptString [Current: html, incorrect]: {{ base ~ '?next=' ~ path|e('url') }}",
                'nested/tree.html.twig:1 [EscapePlan] UrlSchemeFilter -> UrlNormalize -> HtmlAttribute [Current: html, incorrect]: {{ path }}',
                'transformed-component.html.twig:1 [EscapePlan] UrlSchemeFilter -> UrlNormalize -> HtmlAttribute [Current: html, incorrect]: <twig:Link href="{{',
                'transformed-output.html.twig:1 [EscapePlan] HtmlText [Current: none, incorrect]: <app:output />',
                'transformed.html.twig:1 [EscapePlan] UrlSchemeFilter -> UrlNormalize -> HtmlAttribute [Current: html, incorrect]: <app:url />',
                'unsafe-text.html.twig:1 [EscapePlan] HtmlText [Current: none, incorrect]: {{ value }}',
                '[UnsupportedNode] 2 occurrences: The "App\\UnsupportedNode" node has no contextual escaping analyzer.',
                'Analyzed 17 templates and 16 output sites; found 13 contextual escape plans (2 correct, 11 incorrect) and 3 diagnostics.',
                'Proved 1 finite static output site safe.',
                'HTML report: '.$report,
            ], $output);
            $html = file_get_contents($report);
            $this->assertStringContainsString('<input id="search"', $html);
            $this->assertStringContainsString('<strong>7</strong>outer protection present', $html);
            $this->assertStringContainsString('<strong>2</strong>findings to review', $html);
            $this->assertStringContainsString('<strong>2</strong>unsafe today', $html);
            $this->assertStringContainsString('<strong>6</strong>pipelines unavailable', $html);
            $this->assertStringContainsString('data-view="action" aria-pressed="true">Action now <span class="view-count">3</span>', $html);
            $this->assertStringContainsString('data-view="review" aria-pressed="false">Review trust contracts <span class="view-count">2</span>', $html);
            $this->assertStringContainsString('data-view="future" aria-pressed="false">Future Twig support <span class="view-count">6</span>', $html);
            $this->assertStringContainsString('data-view="no-urgent" aria-pressed="false">No urgent action <span class="view-count">10</span>', $html);
            $this->assertStringContainsString('data-summary-status="unsafe"', $html);
            $this->assertStringContainsString('<option value="application">Application (14)</option>', $html);
            $this->assertStringContainsString('<option value="dependency">Dependencies (1)</option>', $html);
            $this->assertStringContainsString('data-assessments="partial url-trust unavailable"', $html);
            $this->assertStringContainsString('data-assessments="review"', $html);
            $this->assertStringContainsString('data-assessments="unsafe"', $html);
            $this->assertStringContainsString('Outer HTML protection present', $html);
            $this->assertStringContainsString('Check the extension runtime safety contract', $html);
            $this->assertStringContainsString('Trusted by current Twig', $html);
            $this->assertStringContainsString('<strong>Current Twig</strong><span class="pipeline-empty">safe for all</span>', $html);
            $this->assertStringContainsString('<strong>Whole output:</strong><code>html</code><small>automatic</small>', $html);
            $this->assertStringContainsString('<strong>Nested <code>path</code>:</strong><code>url</code><small>explicit</small>', $html);
            $this->assertStringContainsString('<strong>Required pipeline</strong><div class="pipeline-steps"><span class="badge operation">JavaScriptString</span>', $html);
            $this->assertStringContainsString('<strong>Why this is required</strong><span>The expression is inside a single-quoted JavaScript string.</span>', $html);
            $this->assertStringContainsString('<strong>1</strong>statically proven safe', $html);
            $this->assertStringContainsString('<strong>Why no escaping is required</strong><span>2 possible static outputs were analyzed directly in CSS Value.</span>', $html);
            $this->assertStringContainsString('<strong>Value provenance</strong><ol><li><code>color</code></li><li><code>random(colors)|first</code></li><li><code>random(colors)</code></li><li><code>colors</code></li><li><code>fixed local array</code></li></ol>', $html);
            $this->assertStringContainsString('<strong>Required pipeline</strong><span class="pipeline-empty">No escaping required</span>', $html);
            $this->assertStringContainsString('data-ownership="application"', $html);
            $this->assertStringContainsString('data-ownership="dependency"', $html);
            $this->assertLessThan(strpos($html, '@Dependency/link.html.twig'), strpos($html, 'contextual.html.twig'));
            $this->assertStringContainsString('URL validation or trusted metadata needed', $html);
            $this->assertStringNotContainsString('review-static.html.twig', $html);
            $this->assertStringContainsString('Never apply <code>e(\'url\')</code> to a complete URL.', $html);
            $this->assertStringContainsString('<symbol id="tree-icon-folder-open"', $html);
            $this->assertStringContainsString('<use href="#tree-icon-folder"></use>', $html);
            $this->assertStringContainsString('<use href="#tree-icon-file"></use>', $html);
            $this->assertStringContainsString('<span>nested</span>', $html);
            $this->assertStringContainsString('<mark class="expression-highlight">{{</mark>', $html);
            $this->assertStringContainsString('<mark class="expression-highlight">&lt;twig:Link href=&quot;{{</mark>', $html);
            $this->assertStringContainsString('<mark class="expression-highlight">    path</mark>', $html);
            $this->assertStringContainsString('<mark class="expression-highlight">}}&quot; /&gt;</mark>', $html);
            $this->assertStringContainsString('&lt;app:url /&gt;', $html);
            $this->assertStringNotContainsString('&lt;a href=&quot;{{ path }}&quot;&gt;Link&lt;/a&gt;', $html);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
            if (is_dir(\dirname($report)) && [] === array_diff(scandir(\dirname($report)), ['.', '..'])) {
                rmdir(\dirname($report));
            }
        }
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
