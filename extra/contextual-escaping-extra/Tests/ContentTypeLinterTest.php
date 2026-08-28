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

use PHPUnit\Framework\Attributes\DataProvider;
use Twig\Environment;
use Twig\Extra\ContextualEscaping\Analysis\Analyzer;
use Twig\Extra\ContextualEscaping\Analysis\DiagnosticCode;
use Twig\Extra\ContextualEscaping\Analysis\EscapeOperation;
use Twig\Extra\ContextualEscaping\Analysis\EscapePlanInferer;
use Twig\Extra\ContextualEscaping\Context\CssContextParser;
use Twig\Extra\ContextualEscaping\Context\HtmlContextParser;
use Twig\Extra\ContextualEscaping\Context\JavaScriptContextParser;
use Twig\Extra\ContextualEscaping\Context\MetaRefreshContextParser;
use Twig\Extra\ContextualEscaping\Context\SrcsetContextParser;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

class ContentTypeLinterTest extends AbstractLinterTestCase
{
    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideRawExpressions
     */
    #[DataProvider('provideRawExpressions')]
    public function testTreatsRawAsTrustForTheInnermostContentType(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideRawExpressions(): iterable
    {
        yield 'HTML fragment' => ['{{ value|raw }}', [[]]];
        yield 'RCDATA' => ['<title>{{ value|raw }}</title>', [[]]];
        yield 'quoted attribute' => ['<div title="{{ value|raw }}">', [[]]];
        yield 'conditional operand' => ['{{ condition ? value|raw : other }}', [[EscapeOperation::HtmlText]]];
        yield 'URL with enclosing attribute' => ['<a href="{{ value|raw }}">', [[EscapeOperation::HtmlAttribute]]];
        yield 'unquoted URL with enclosing attribute' => ['<a href={{ value|raw }}>', [[EscapeOperation::HtmlAttributeUnquoted]]];
        yield 'JavaScript with enclosing attribute' => ['<button onclick="{{ value|raw }}">', [[EscapeOperation::HtmlAttribute]]];
        yield 'CSS with enclosing attribute' => ['<div style="{{ value|raw }}">', [[EscapeOperation::HtmlAttribute]]];
        yield 'HTML with enclosing attribute' => ['<iframe srcdoc="{{ value|raw }}"></iframe>', [[EscapeOperation::HtmlAttribute]]];
    }

    public function testContextualEscapingStillAppliesWhenLegacyAutoescapingIsDisabled(): void
    {
        $result = $this->lint('{% autoescape false %}{{ value }}{% endautoescape %}');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }

    public function testRejectsGeneratorOutput(): void
    {
        $environment = new Environment(new ArrayLoader(), ['autoescape' => false, 'optimizations' => 0]);
        $module = $environment->parse($environment->tokenize(new Source('{{ value }}', 'index.html.twig')));
        $module->getNode('body')->getNode(0)->getNode('expr')->setAttribute('is_generator', true);

        $result = (new Analyzer(new HtmlContextParser(new JavaScriptContextParser(), new CssContextParser(), new MetaRefreshContextParser(), new SrcsetContextParser()), new EscapePlanInferer()))->analyze($module);

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
    }

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideMatchingExplicitEscapes
     */
    #[DataProvider('provideMatchingExplicitEscapes')]
    public function testAcceptsMatchingExplicitEscaping(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public function testIgnoresArgumentsOfNonEscapingFilters(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFilter(new TwigFilter('format_value', static fn ($value, $first = null, $second = null, $third = null) => $value));

        $result = $this->createLinter($environment)->lint(new Source('{{ value|format_value(first: 1, third: 3) }}', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
    }

    public static function provideMatchingExplicitEscapes(): iterable
    {
        yield 'HTML text' => ['{{ value|e("html") }}', [[]]];
        yield 'default escape strategy' => ['{{ value|e }}', [[]]];
        yield 'HTML attribute' => ['<div title="{{ value|e("html_attr") }}">', [[]]];
        yield 'HTML attribute autoescape block' => ['{% autoescape "html_attr" %}<div title="{{ value }}">{% endautoescape %}', [[]]];
        yield 'escape used as a function argument' => ['{{ max(value|e("js"), "fallback") }}', [[EscapeOperation::HtmlText]]];
    }

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideTypedIntermediateEscapes
     */
    #[DataProvider('provideTypedIntermediateEscapes')]
    public function testAppliesOuterEscapingToTypedIntermediateContent(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideTypedIntermediateEscapes(): iterable
    {
        yield 'HTML in quoted attribute' => ['<div title="{{ value|e("html") }}">', [[EscapeOperation::HtmlAttribute]]];
        yield 'quoted attribute content in unquoted attribute' => ['<div title={{ value|e("html_attr") }}>', [[EscapeOperation::HtmlAttributeUnquoted]]];
        yield 'quoted autoescape in unquoted attribute' => ['{% autoescape "html_attr" %}<div title={{ value }}>{% endautoescape %}', [[EscapeOperation::HtmlAttributeUnquoted]]];
        yield 'conditional operand' => ['{{ condition ? value|e("js") : other }}', [[EscapeOperation::HtmlText]]];
        yield 'HTML autoescape in quoted attribute' => ['{% autoescape "html" %}<div title="{{ value }}">{% endautoescape %}', [[EscapeOperation::HtmlAttribute]]];
        yield 'URL in quoted attribute' => ['<a href="{{ value|e("url") }}">', [[EscapeOperation::HtmlAttribute]]];
    }

    /**
     * @dataProvider provideUnknownExplicitEscapingStrategies
     */
    #[DataProvider('provideUnknownExplicitEscapingStrategies')]
    public function testRejectsAnUnknownExplicitEscapingStrategy(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::MismatchedExplicitEscaping], $this->getDiagnosticCodes($result));
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }

    public static function provideUnknownExplicitEscapingStrategies(): iterable
    {
        yield 'dynamic' => ['{{ value|e(strategy) }}'];
        yield 'custom' => ['{{ value|e("custom") }}'];
    }

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideCapturedContent
     */
    #[DataProvider('provideCapturedContent')]
    public function testPreservesProvenCapturedContentTypes(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideCapturedContent(): iterable
    {
        yield 'HTML fragment' => [
            '{% set content %}<b>{{ value }}</b>{% endset %}{{ content }}',
            [[EscapeOperation::HtmlText], []],
        ];
        yield 'HTML fragment in an attribute' => [
            '{% set content %}<b>{{ value }}</b>{% endset %}<div title="{{ content }}">',
            [[EscapeOperation::HtmlText], [EscapeOperation::HtmlAttribute]],
        ];
        yield 'apply loses the HTML type' => [
            '{% apply upper %}<b>{{ value }}</b>{% endapply %}',
            [[EscapeOperation::HtmlText], [EscapeOperation::HtmlText]],
        ];
        yield 'matching captures in every branch' => [
            '{% if condition %}{% set content %}<b>{{ first }}</b>{% endset %}{% else %}{% set content %}<i>{{ second }}</i>{% endset %}{% endif %}{{ content }}',
            [[EscapeOperation::HtmlText], [EscapeOperation::HtmlText], []],
        ];
        yield 'capture in only one branch' => [
            '{% if condition %}{% set content %}<b>{{ value }}</b>{% endset %}{% endif %}{{ content }}',
            [[EscapeOperation::HtmlText], [EscapeOperation::HtmlText]],
        ];
    }

    public function testStandaloneJavaScriptCaptureRemainsPlainText(): void
    {
        $result = $this->lint('{% set content %}const child = "{{ value }}";{% endset %}const output = {{ content }};', 'index.js.twig');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::JavaScriptString], [EscapeOperation::JavaScriptValue]], $this->getPlans($result));
    }

    public function testStandaloneCssCaptureRemainsPlainText(): void
    {
        $result = $this->lint('{% set content %}.child { color: {{ value }}; }{% endset %}.parent { color: {{ content }}; }', 'index.css.twig');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::CssValue], [EscapeOperation::CssValue]], $this->getPlans($result));
    }

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideIncompleteCapturedContent
     */
    #[DataProvider('provideIncompleteCapturedContent')]
    public function testRejectsIncompleteCapturedContent(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::IncompleteStructuredOutput], $this->getDiagnosticCodes($result));
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideIncompleteCapturedContent(): iterable
    {
        yield 'optimized static capture' => [
            '{% set content %}<div title="{% endset %}{{ content }}',
            [[EscapeOperation::HtmlText]],
        ];
        yield 'dynamic capture' => [
            '{% set content %}<div title="{{ value }}{% endset %}{{ content }}',
            [[EscapeOperation::HtmlAttribute], [EscapeOperation::HtmlText]],
        ];
    }

    public function testUsesExactCallableSafeContentTypes(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('safe_html', static fn () => '', ['is_safe' => ['html']]));
        $environment->addFunction(new TwigFunction('safe_url', static fn () => '', ['is_safe' => ['url']]));
        $environment->addFunction(new TwigFunction('safe_all', static fn () => '', ['is_safe' => ['all']]));

        $result = $this->createLinter($environment)->lint(new Source('{{ safe_html() }}<div title="{{ safe_html() }}"><a href="{{ safe_url() }}">{{ safe_all() }}', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([
            [],
            [EscapeOperation::HtmlAttribute],
            [EscapeOperation::HtmlAttribute],
            [EscapeOperation::HtmlText],
        ], $this->getPlans($result));
    }

    public function testUsesDeclaredCssTypes(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('safe_css', static fn () => '', ['is_safe' => ['css']]));
        $environment->addFunction(new TwigFunction('safe_css_string', static fn () => '', ['is_safe' => ['css_string']]));
        $environment->addFunction(new TwigFunction('safe_url', static fn () => '', ['is_safe' => ['url']]));

        $result = $this->createLinter($environment)->lint(new Source('<style>{{ safe_css() }}</style><div style="{{ safe_css() }}"><style>.x { color: {{ safe_css() }}; }</style><style>.x { content: "{{ safe_css_string() }}"; color: {{ safe_css_string() }}; }</style><style>.x { background: url({{ safe_url() }}); }</style>', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([
            [],
            [EscapeOperation::HtmlAttribute],
            [],
            [],
            [EscapeOperation::CssValue],
            [EscapeOperation::UrlNormalize, EscapeOperation::CssString],
        ], $this->getPlans($result));
    }

    public function testTreatsOutputAfterTrustedCssAsAmbiguous(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('safe_css', static fn () => '', ['is_safe' => ['css']]));

        $result = $this->createLinter($environment)->lint(new Source('<style>.x { color: {{ safe_css() }}; width: {{ value }}; }</style>', 'index.html.twig'));

        $this->assertSame([DiagnosticCode::AmbiguousCssContext], $this->getDiagnosticCodes($result));
        $this->assertSame([[]], $this->getPlans($result));
    }

    public function testPreservesDeclaredFilterContentTypes(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFilter(new TwigFilter('preserve_html', static fn ($value) => $value, ['preserves_safety' => ['html']]));

        $result = $this->createLinter($environment)->lint(new Source('{% set content %}<b>{{ value }}</b>{% endset %}{{ content|preserve_html }}', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText], []], $this->getPlans($result));
    }

    public function testDoesNotTreatABooleanFilterArgumentAsAutomaticEscaping(): void
    {
        $result = $this->lint('{{ value|raw|slice(0, 3, true) }}');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }
}
