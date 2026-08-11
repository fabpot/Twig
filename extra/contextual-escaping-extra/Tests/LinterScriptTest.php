<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Tests;

use PHPUnit\Framework\TestCase;

final class LinterScriptTest extends TestCase
{
    public function testLintsTemplatesWithTheApplicationTwigEnvironment(): void
    {
        $projectDirectory = __DIR__.'/Fixtures/symfony-app';
        $report = $projectDirectory.'/var/contextual-escaping.html';
        $jsonReport = $projectDirectory.'/var/contextual-escaping.json';

        try {
            [$status, $output] = $this->runScript($projectDirectory);

            $this->assertSame(1, $status);
            $this->assertSame([
                "callable-contract.html.twig:1 [EscapePlan] UrlNormalize -> CssString [Current: html, incorrect]: {{ asset('image.svg') }}",
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
                "unsafe-text.html.twig:1 [EscapePlan] HtmlText [Current: none, incorrect]: {{ condition ? value|e('html')|replace({'&lt;': '<'}) : other|e('html') }}",
                '[UnsupportedNode] 2 occurrences: The "App\\UnsupportedNode" node has no contextual escaping analyzer.',
                'Analyzed 18 templates and 17 output sites; found 14 contextual escape plans (2 correct, 12 incorrect) and 3 diagnostics.',
                'Proved 1 finite static output site safe.',
                'HTML report: '.$report,
                'JSON report: '.$jsonReport,
            ], $output);
            $html = file_get_contents($report);
            $json = json_decode(file_get_contents($jsonReport), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame(1, $json['schema']);
            $this->assertCount(14, $json['findings']);
            $this->assertSame(array_column($json['findings'], 'id'), array_values(array_unique(array_column($json['findings'], 'id'))));
            $this->assertStringContainsString('<input id="search"', $html);
            $this->assertStringContainsString('<strong>1</strong>protected today', $html);
            $this->assertStringContainsString('<strong>3</strong>trust reviews', $html);
            $this->assertStringContainsString('<strong>8</strong>unsafe today', $html);
            $this->assertStringContainsString('<strong>7</strong>pipelines unavailable', $html);
            $this->assertStringContainsString('data-view="action" aria-pressed="true">Action now <span class="view-count">9</span>', $html);
            $this->assertStringContainsString('data-view="review" aria-pressed="false">Review trust contracts <span class="view-count">3</span>', $html);
            $this->assertStringContainsString('data-view="future" aria-pressed="false">Future Twig support <span class="view-count">7</span>', $html);
            $this->assertStringContainsString('data-view="no-urgent" aria-pressed="false">No urgent action <span class="view-count">4</span>', $html);
            $this->assertStringContainsString('data-summary-status="unsafe"', $html);
            $this->assertStringContainsString('<option value="application">Application (15)</option>', $html);
            $this->assertStringContainsString('<option value="dependency">Dependencies (1)</option>', $html);
            $this->assertStringContainsString('data-assessments="unsafe url-trust unavailable"', $html);
            $this->assertStringContainsString('data-assessments="review"', $html);
            $this->assertStringContainsString('data-assessments="partial"', $html);
            $this->assertStringContainsString('Protected today', $html);
            $this->assertStringContainsString('Check the extension runtime safety contract', $html);
            $this->assertStringContainsString('Declared safe to Twig', $html);
            $this->assertStringContainsString('<div class="recommended-action"><strong>Recommended action</strong>', $html);
            $this->assertStringContainsString('<div class="protection-covered protection-positive"><strong>Already protected</strong>', $html);
            $this->assertStringContainsString('<div class="protection-missing protection-negative"><strong>Still missing</strong>', $html);
            $this->assertStringContainsString('<div class="protection-missing protection-positive"><strong>Protection gap</strong><p>Nothing is missing for this output context.</p>', $html);
            $this->assertStringContainsString('<strong>Allow only safe URL schemes</strong><small>Rejects dangerous schemes such as javascript in a complete URL.</small>', $html);
            $this->assertStringContainsString('<option value="UrlSchemeFilter" title="Rejects dangerous schemes such as javascript in a complete URL.">Allow only safe URL schemes</option>', $html);
            $this->assertStringContainsString('<strong>Current Twig protection</strong><span class="pipeline-empty">safe for all</span>', $html);
            $this->assertStringContainsString('<strong>Whole output</strong><span>HTML escaping</span><code>html</code><small>automatic</small>', $html);
            $this->assertStringContainsString('<strong>Nested <code>path</code></strong><span>URL component encoding</span><code>url</code><small>explicit</small>', $html);
            $this->assertStringContainsString('<strong>Inferred contextual pipeline</strong><div class="pipeline-steps"><span class="operation-step" title="Prevents the value from ending the quoted JavaScript string."><span class="badge operation">Escape a JavaScript string</span><code>JavaScriptString</code>', $html);
            $this->assertStringContainsString('<strong>Why this context matters</strong><span>The expression is inside a single-quoted JavaScript string.</span>', $html);
            $this->assertStringContainsString('<strong>1</strong>statically proven safe', $html);
            $this->assertStringContainsString('<strong>Why no escaping is needed</strong><span>2 possible static outputs were analyzed directly in CSS Value.</span>', $html);
            $this->assertStringContainsString('<strong>Value provenance</strong><ol><li><code>color</code></li><li><code>random(colors)|first</code></li><li><code>random(colors)</code></li><li><code>colors</code></li><li><code>fixed local array</code></li></ol>', $html);
            $this->assertStringContainsString('<strong>Inferred contextual pipeline</strong><span class="pipeline-empty">No contextual escaping needed</span>', $html);
            $this->assertStringContainsString('<strong>Why the analyzer trusts this value</strong>', $html);
            $this->assertStringContainsString('<code>asset()</code> is treated as <span class="badge contract-result">Complete URL</span> instead of plain text.', $html);
            $this->assertStringContainsString('<dt>Recognized by</dt><dd>Symfony integration</dd>', $html);
            $this->assertStringContainsString('<dt>Resolved callable</dt><dd><code>Symfony\\Bridge\\Twig\\Extension\\AssetExtension::getAssetUrl</code></dd>', $html);
            $this->assertStringContainsString('<dt>Guarantee</dt><dd>The result represents an entire URL value, not an individual path, query, or fragment component.</dd>', $html);
            $this->assertStringContainsString('<strong>Effect on this finding</strong><span>The result is analyzed as a complete URL rather than plain text. At a URL start, no scheme filter is added. The inferred contextual pipeline still includes any encoding needed by the surrounding context.</span>', $html);
            $this->assertStringContainsString('data-ownership="application"', $html);
            $this->assertStringContainsString('data-ownership="dependency"', $html);
            $this->assertLessThan(strpos($html, '@Dependency/link.html.twig'), strpos($html, 'contextual.html.twig'));
            $this->assertStringContainsString('URL validation or trusted metadata needed', $html);
            $this->assertStringContainsString('data-assessments="diagnostic diagnostic-limitation"', $html);
            $this->assertStringContainsString('Keep this structure static or provide a supported semantic contract', $html);
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
            if (is_file($jsonReport)) {
                unlink($jsonReport);
            }
            if (is_dir(\dirname($report)) && [] === array_diff(scandir(\dirname($report)), ['.', '..'])) {
                rmdir(\dirname($report));
            }
        }
    }

    public function testComparesAgainstABaseline(): void
    {
        $projectDirectory = __DIR__.'/Fixtures/symfony-app';
        $report = $projectDirectory.'/var/contextual-escaping.html';
        $jsonReport = $projectDirectory.'/var/contextual-escaping.json';
        $baseline = $projectDirectory.'/var/contextual-escaping-baseline.json';

        try {
            [$status] = $this->runScript($projectDirectory);
            $this->assertSame(1, $status);
            copy($jsonReport, $baseline);

            [$status, $output] = $this->runScript($projectDirectory, $baseline);
            $this->assertSame(0, $status);
            $this->assertSame('Baseline diff: 0 new, 0 resolved, 14 unchanged.', $output[array_key_last($output)]);

            $contents = json_decode(file_get_contents($baseline), true, flags: \JSON_THROW_ON_ERROR);
            $contents['findings'][] = ['id' => str_repeat('f', 64), 'type' => 'diagnostic'];
            file_put_contents($baseline, json_encode($contents, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
            [$status, $output] = $this->runScript($projectDirectory, $baseline);
            $this->assertSame(0, $status);
            $this->assertSame('Baseline diff: 0 new, 1 resolved, 14 unchanged.', $output[array_key_last($output)]);

            copy($jsonReport, $baseline);
            $contents = json_decode(file_get_contents($baseline), true, flags: \JSON_THROW_ON_ERROR);
            array_shift($contents['findings']);
            file_put_contents($baseline, json_encode($contents, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");

            [$status, $output] = $this->runScript($projectDirectory, $baseline);
            $this->assertSame(1, $status);
            $this->assertSame('Baseline diff: 1 new, 0 resolved, 13 unchanged.', $output[array_key_last($output)]);
        } finally {
            foreach ([$report, $jsonReport, $baseline] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
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
        $this->assertSame(['Usage: lint-contextual-escaping /path/to/symfony-app [--baseline=/path/to/contextual-escaping.json]'], $output);
    }

    /**
     * @return array{int, list<string>}
     */
    private function runScript(?string $projectDirectory = null, ?string $baseline = null): array
    {
        $command = escapeshellarg(\PHP_BINARY).' '.escapeshellarg(\dirname(__DIR__).'/bin/lint-contextual-escaping');
        if (null !== $projectDirectory) {
            $command .= ' '.escapeshellarg($projectDirectory);
        }
        if (null !== $baseline) {
            $command .= ' '.escapeshellarg('--baseline='.$baseline);
        }

        $output = [];
        exec($command.' 2>&1', $output, $status);

        return [$status, $output];
    }
}
