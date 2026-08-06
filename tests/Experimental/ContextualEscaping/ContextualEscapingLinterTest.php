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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Experimental\ContextualEscaping\AnalysisResult;
use Twig\Experimental\ContextualEscaping\ContextualEscapingAnalyzer;
use Twig\Experimental\ContextualEscaping\ContextualEscapingLinter;
use Twig\Experimental\ContextualEscaping\DiagnosticCode;
use Twig\Experimental\ContextualEscaping\EnvironmentTemplateResolver;
use Twig\Experimental\ContextualEscaping\EscapeOperation;
use Twig\Experimental\ContextualEscaping\HtmlContextParser;
use Twig\Loader\ArrayLoader;
use Twig\Node\Node;
use Twig\Source;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;
use Twig\TwigFilter;
use Twig\TwigFunction;

class ContextualEscapingLinterTest extends TestCase
{
    public function testSkipsNonHtmlTemplatesByDefault(): void
    {
        $result = $this->lint('{{ value }}', 'index.js.twig');

        $this->assertTrue($result->isSkipped());
        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([], $result->getInferredEscapes());
    }

    public function testCanForceAnalysisOfANonHtmlTemplate(): void
    {
        $result = $this->lint('{{ value }}', 'index.twig', true);

        $this->assertFalse($result->isSkipped());
        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }

    public function testCanReuseTheLinter(): void
    {
        $linter = $this->createLinter(new Environment(new ArrayLoader(), ['optimizations' => 0]));

        $invalid = $linter->lint(new Source('<a href="{{ value }}">', 'first.html.twig'));
        $valid = $linter->lint(new Source('<p>{{ value }}</p>', 'second.html.twig'));

        $this->assertSame([DiagnosticCode::UnsupportedAttributeContext], $this->getDiagnosticCodes($invalid));
        $this->assertSame([], $valid->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($valid));
    }

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     */
    #[DataProvider('provideSupportedContexts')]
    public function testInfersPlansForSupportedContexts(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideSupportedContexts(): iterable
    {
        yield 'HTML text' => ['<p>{{ value }}</p>', [[EscapeOperation::HtmlText]]];
        yield 'less-than sign in text' => ['I <3 {{ value }}', [[EscapeOperation::HtmlText]]];
        yield 'closed comment before output' => ['<!-- comment -->{{ value }}', [[EscapeOperation::HtmlText]]];
        yield 'doctype before output' => ['<!DOCTYPE html>{{ value }}', [[EscapeOperation::HtmlText]]];
        yield 'double-quoted attribute' => ['<div title="{{ value }}">', [[EscapeOperation::HtmlAttribute]]];
        yield 'single-quoted attribute' => ["<div title='{{ value }}'>", [[EscapeOperation::HtmlAttribute]]];
        yield 'unquoted attribute' => ['<div title={{ value }}>', [[EscapeOperation::HtmlAttributeUnquoted]]];
        yield 'title RCDATA' => ['<title>{{ value }}</title>', [[EscapeOperation::HtmlRcdata]]];
        yield 'self-closing title syntax' => ['<title/>{{ value }}</title>', [[EscapeOperation::HtmlRcdata]]];
        yield 'textarea RCDATA' => ['<textarea>{{ value }}</textarea>', [[EscapeOperation::HtmlRcdata]]];
        yield 'case-insensitive RCDATA element' => ['<TITLE>{{ value }}</TITLE>', [[EscapeOperation::HtmlRcdata]]];
        yield 'similar RCDATA end tag' => ['<title></titlex>{{ value }}</title>', [[EscapeOperation::HtmlRcdata]]];
        yield 'closed raw-text element before output' => ['<script>let value = 1;</script>{{ value }}', [[EscapeOperation::HtmlText]]];
        yield 'script escaped end tag before output' => ['<script><!-- </script>{{ value }}', [[EscapeOperation::HtmlText]]];
        yield 'HTML fragment without a closing element' => ['<div>{{ value }}', [[EscapeOperation::HtmlText]]];
        yield 'optional unquoted attribute value' => ['<div title={% if enabled %}{{ value }}{% endif %}>', [[EscapeOperation::HtmlAttributeUnquoted]]];
        yield 'conditional quoted attribute' => ['<div {% if enabled %}title="{{ value }}"{% endif %}>', [[EscapeOperation::HtmlAttribute]]];
    }

    public function testTreatsDirectStringConstantsAsStaticTemplateText(): void
    {
        $result = $this->lint('{{ "<script>" }}{{ value }}{{ "</script>" }}');

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
        $this->assertSame([], $this->getPlans($result));
    }

    #[DataProvider('provideUnsupportedAttributes')]
    public function testRejectsAttributesRequiringLanguageSpecificAnalysis(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::UnsupportedAttributeContext], $this->getDiagnosticCodes($result));
    }

    public static function provideUnsupportedAttributes(): iterable
    {
        yield 'URL' => ['<a href="{{ value }}">'];
        yield 'case-insensitive URL' => ['<a HREF="{{ value }}">'];
        yield 'namespaced URL' => ['<a svg:href="{{ value }}">'];
        yield 'custom data URL' => ['<div data-url="{{ value }}">'];
        yield 'custom source attribute' => ['<div image-src="{{ value }}">'];
        yield 'ping URL list' => ['<a ping="{{ value }}">'];
        yield 'srcset' => ['<img srcset="{{ value }}">'];
        yield 'link image srcset' => ['<link imagesrcset="{{ value }}">'];
        yield 'style' => ['<div style="{{ value }}">'];
        yield 'escaped CSS string' => ['<div style="{{ value|e("css") }}">'];
        yield 'event handler' => ['<button onclick="{{ value }}">'];
        yield 'escaped JavaScript string' => ['<button onclick="{{ value|e("js") }}">'];
        yield 'embedded HTML' => ['<iframe srcdoc="{{ value }}"></iframe>'];
        yield 'meta refresh content' => ['<meta http-equiv="refresh" content="{{ value }}">'];
    }

    public function testCollectsIndependentDiagnosticsFromEveryBranch(): void
    {
        $result = $this->lint('{% if a %}<!-- {{ first }} -->{% else %}<a href="{{ second }}"></a>{% endif %}');

        $this->assertSame([
            DiagnosticCode::CommentInterpolation,
            DiagnosticCode::UnsupportedAttributeContext,
        ], $this->getDiagnosticCodes($result));
    }

    public function testDiagnosticsRetainTheTemplateLocation(): void
    {
        $result = $this->lint("<p>text</p>\n<a href=\"{{ value }}\">link</a>", 'page.html.twig');
        $diagnostic = $result->getDiagnostics()[0];

        $this->assertSame(2, $diagnostic->getTemplateLine());
        $this->assertSame('page.html.twig', $diagnostic->getTemplateName());
    }

    public function testRejectsCommentInterpolation(): void
    {
        $result = $this->lint('<!-- {{ value }} -->');

        $this->assertSame([DiagnosticCode::CommentInterpolation], $this->getDiagnosticCodes($result));
    }

    #[DataProvider('provideUnsupportedRawTextContexts')]
    public function testRejectsOutputInRawText(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
    }

    public static function provideUnsupportedRawTextContexts(): iterable
    {
        yield 'script' => ['<script>{{ value }}</script>'];
        yield 'self-closing script syntax' => ['<script/>{{ value }}</script>'];
        yield 'double-escaped script data' => ['<script><!-- <script> </script> {{ value }}</script>'];
        yield 'branch with irrelevant script candidate' => ['<script><!--{% if condition %}<foo>{% else %}<bar>{% endif %}{{ value }}</script>'];
        yield 'style' => ['<style>{{ value }}</style>'];
        yield 'legacy raw-text element' => ['<xmp>{{ value }}</xmp>'];
        yield 'iframe fallback content' => ['<iframe>{{ value }}</iframe>'];
    }

    public function testCollectsEveryUnsupportedOutputContextDiagnostic(): void
    {
        $result = $this->lint('<script>{{ first }}{{ second }}</script>');

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext, DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
    }

    #[DataProvider('provideStructuralInterpolation')]
    public function testRejectsStructuralInterpolation(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::UnsupportedStructuralInterpolation], $this->getDiagnosticCodes($result));
    }

    public static function provideStructuralInterpolation(): iterable
    {
        yield 'tag name' => ['<{{ tag }}>'];
        yield 'attribute name' => ['<div {{ attribute_name }}="value">'];
        yield 'partial attribute name' => ['<div data-{{ suffix }}="value">'];
        yield 'doctype public identifier' => ['<!DOCTYPE html PUBLIC "identifier>{{ value }}">'];
    }

    public function testJoinsCompatibleIfBranches(): void
    {
        $result = $this->lint('{% if condition %}<b>{{ first }}</b>{% else %}<i>{{ second }}</i>{% endif %}');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([
            [EscapeOperation::HtmlText],
            [EscapeOperation::HtmlText],
        ], $this->getPlans($result));
    }

    #[DataProvider('provideConditions')]
    public function testRejectsIncompatibleIfBranches(string $condition): void
    {
        $result = $this->lint(\sprintf('{%% if %s %%}<script>{%% endif %%}{{ value }}', $condition));

        $this->assertSame([DiagnosticCode::AmbiguousControlFlow], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('raw text in <script> and HTML text', $result->getDiagnostics()[0]->getMessage());
    }

    public static function provideConditions(): iterable
    {
        yield 'dynamic condition' => ['condition'];
        yield 'statically false condition' => ['false'];
    }

    public function testAcceptsAContextStableLoop(): void
    {
        $result = $this->lint('<ul>{% for item in items %}<li>{{ item }}</li>{% else %}<li>empty</li>{% endfor %}</ul>');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }

    public function testRejectsAContextChangingLoop(): void
    {
        $result = $this->lint('{% for item in items %}<div title="{% endfor %}');

        $this->assertSame([DiagnosticCode::UnstableLoop], $this->getDiagnosticCodes($result));
    }

    public function testRejectsALoopWithAnIncompatibleElseContext(): void
    {
        $result = $this->lint('{% for item in items %}text{% else %}<script>{% endfor %}');

        $this->assertSame([DiagnosticCode::AmbiguousControlFlow], $this->getDiagnosticCodes($result));
    }

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
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

        $result = (new ContextualEscapingAnalyzer(new HtmlContextParser()))->analyze($module);

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
    }

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
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

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
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

    public function testDefersSafeLanguageContentUntilLexicalAnalysis(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('safe_js', static fn () => '', ['is_safe' => ['js']]));
        $environment->addFunction(new TwigFunction('safe_css', static fn () => '', ['is_safe' => ['css']]));

        $result = $this->createLinter($environment)->lint(new Source('<button onclick="{{ safe_js() }}"><div style="{{ safe_css() }}">', 'index.html.twig'));

        $this->assertSame([
            DiagnosticCode::UnsupportedAttributeContext,
            DiagnosticCode::UnsupportedAttributeContext,
        ], $this->getDiagnosticCodes($result));
        $this->assertSame([], $result->getInferredEscapes());
    }

    public function testPreservesDeclaredFilterContentTypes(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFilter(new TwigFilter('preserve_html', static fn ($value) => $value, ['preserves_safety' => ['html']]));

        $result = $this->createLinter($environment)->lint(new Source('{% set content %}<b>{{ value }}</b>{% endset %}{{ content|preserve_html }}', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText], []], $this->getPlans($result));
    }

    /**
     * @param array<string, string>       $templates
     * @param list<list<EscapeOperation>> $expectedPlans
     */
    #[DataProvider('provideSupportedComposition')]
    public function testAnalyzesStaticTemplateComposition(array $templates, string $name, array $expectedPlans): void
    {
        $result = $this->lintTemplates($templates, $name);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideSupportedComposition(): iterable
    {
        yield 'standalone block' => [
            ['index.html.twig' => '{% block content %}{{ value }}{% endblock %}'],
            'index.html.twig',
            [[EscapeOperation::HtmlText]],
        ];
        yield 'block function' => [
            ['index.html.twig' => '{{ block("content") }}{% block content %}{{ value }}{% endblock %}'],
            'index.html.twig',
            [[EscapeOperation::HtmlText], [EscapeOperation::HtmlText]],
        ];
        yield 'missing optional block' => [
            ['index.html.twig' => '{{ block("missing") ?? "fallback" }}'],
            'index.html.twig',
            [[EscapeOperation::HtmlText]],
        ];
        yield 'inheritance' => [
            [
                'base.html.twig' => '<div title="{% block content %}{% endblock %}">',
                'index.html.twig' => '{% extends "base.html.twig" %}{% block content %}{{ value }}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'parent block' => [
            [
                'base.html.twig' => '{% block content %}{{ parent_value }}{% endblock %}',
                'index.html.twig' => '{% extends "base.html.twig" %}{% block content %}{{ child_value }}{{ parent() }}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText], [EscapeOperation::HtmlText]],
        ];
        yield 'multi-level inheritance' => [
            [
                'base.html.twig' => '<div title="{% block content %}{{ base_value }}{% endblock %}">',
                'middle.html.twig' => '{% extends "base.html.twig" %}{% block content %}{{ middle_value }}{{ parent() }}{% endblock %}',
                'index.html.twig' => '{% extends "middle.html.twig" %}{% block content %}{{ child_value }}{{ parent() }}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlAttribute], [EscapeOperation::HtmlAttribute], [EscapeOperation::HtmlAttribute]],
        ];
        yield 'nested rendering through a shared parent' => [
            [
                'base.html.twig' => '{% block content %}base{% endblock %}',
                'inner.html.twig' => '{% extends "base.html.twig" %}{% block content %}{{ value }}{% endblock %}',
                'index.html.twig' => '{% extends "base.html.twig" %}{% block content %}{% include "inner.html.twig" %}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText]],
        ];
        yield 'include tag' => [
            [
                'index.html.twig' => '<div title="{% include "partial.html.twig" %}">',
                'partial.html.twig' => '{{ value }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'typed include variable' => [
            [
                'index.html.twig' => '{% set content %}<b>{{ value }}</b>{% endset %}{% include "partial.html.twig" with {content: content} only %}',
                'partial.html.twig' => '{{ content }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText], []],
        ];
        yield 'include function' => [
            [
                'index.html.twig' => '{{ include("partial.html.twig") }}',
                'partial.html.twig' => '{{ value }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText]],
        ];
        yield 'transformed include function' => [
            [
                'index.html.twig' => '{{ include("partial.html.twig")|upper }}',
                'partial.html.twig' => '{{ value }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText], [EscapeOperation::HtmlText]],
        ];
        yield 'trusted include function' => [
            [
                'index.html.twig' => '{{ include("partial.html.twig")|raw }}',
                'partial.html.twig' => '{{ value }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText], []],
        ];
        yield 'assigned include function' => [
            [
                'index.html.twig' => '{% set content = include("partial.html.twig") %}{{ content }}',
                'partial.html.twig' => '{{ value }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText], []],
        ];
        yield 'include function in a with expression' => [
            [
                'index.html.twig' => '{% with {content: include("partial.html.twig")} %}{{ content }}{% endwith %}',
                'partial.html.twig' => '{{ value }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText], []],
        ];
        yield 'include fallback list' => [
            [
                'index.html.twig' => '{% include ["missing.html.twig", "partial.html.twig"] %}',
                'partial.html.twig' => '{{ value }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText]],
        ];
        yield 'missing include ignored' => [
            ['index.html.twig' => 'before{% include "missing.html.twig" ignore missing %}after'],
            'index.html.twig',
            [],
        ];
        yield 'self macro' => [
            [
                'index.html.twig' => '<div title="{% macro value() %}{{ value }}{% endmacro %}{{ _self.value() }}">',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'transformed self macro' => [
            [
                'index.html.twig' => '{% macro value() %}{{ value }}{% endmacro %}{{ _self.value()|upper }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText], [EscapeOperation::HtmlText]],
        ];
        yield 'assigned self macro' => [
            [
                'index.html.twig' => '{% macro value() %}{{ value }}{% endmacro %}{% set content = _self.value() %}{{ content }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText], []],
        ];
        yield 'context-stable recursive macro' => [
            [
                'index.html.twig' => '{% macro tree(item) %}{{ item.name }}{% if item.children %}{{ _self.tree(item.children) }}{% endif %}{% endmacro %}{{ _self.tree(tree) }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText]],
        ];
        yield 'typed macro argument' => [
            [
                'index.html.twig' => '{% macro wrapper(content) %}<div>{{ content }}</div>{% endmacro %}{% set content %}<b>{{ value }}</b>{% endset %}{{ _self.wrapper(content) }}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText], []],
        ];
        yield 'imported macro' => [
            [
                'index.html.twig' => '<div title="{% import "macros.html.twig" as macros %}{{ macros.value() }}">',
                'macros.html.twig' => '{% macro value() %}{{ value }}{% endmacro %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'self imported macro' => [
            [
                'index.html.twig' => '<div title="{% macro value() %}{{ value }}{% endmacro %}{% import _self as macros %}{{ macros.value() }}">',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'from imported macro' => [
            [
                'index.html.twig' => '<div title="{% from "macros.html.twig" import value %}{{ value() }}">',
                'macros.html.twig' => '{% macro value() %}{{ value }}{% endmacro %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'external block' => [
            [
                'index.html.twig' => '<div title="{{ block("content", "blocks.html.twig") }}">',
                'blocks.html.twig' => '{% block content %}{{ value }}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'block trait' => [
            [
                'index.html.twig' => '<div title="{% use "blocks.html.twig" with content as value %}{{ block("value") }}">',
                'blocks.html.twig' => '{% block content %}{{ value }}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'embed' => [
            [
                'base.html.twig' => '<div title="{% block content %}{% endblock %}">',
                'index.html.twig' => '{% embed "base.html.twig" %}{% block content %}{{ value }}{% endblock %}{% endembed %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlAttribute]],
        ];
    }

    public function testPropagatesContextAcrossIncludedTemplates(): void
    {
        $result = $this->lintTemplates([
            'index.html.twig' => '{% include "open-script.html.twig" %}{{ value }}</script>',
            'open-script.html.twig' => '<script>',
        ], 'index.html.twig');

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
    }

    public function testRejectsRecursiveComposition(): void
    {
        $result = $this->lintTemplates([
            'index.html.twig' => '{% include "index.html.twig" %}',
        ], 'index.html.twig');

        $this->assertSame([DiagnosticCode::UnsupportedTemplateComposition], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('Recursive composition', $result->getDiagnostics()[0]->getMessage());
    }

    #[DataProvider('provideUnsupportedComposition')]
    public function testRejectsUnsupportedTemplateComposition(string $template): void
    {
        $result = $this->lint($template);
        $codes = $this->getDiagnosticCodes($result);

        $this->assertContains(DiagnosticCode::UnsupportedTemplateComposition, $codes);
        $this->assertNotContains(DiagnosticCode::UnsupportedNode, $codes);
    }

    public static function provideUnsupportedComposition(): iterable
    {
        yield 'include tag' => ['{% include "other.html.twig" %}'];
        yield 'include function' => ['{{ include("other.html.twig") }}'];
        yield 'include function in an assignment' => ['{% set content = include("other.html.twig") %}'];
        yield 'import' => ['{% import "macros.html.twig" as macros %}'];
        yield 'from import' => ['{% from "macros.html.twig" import input %}'];
        yield 'inheritance' => ['{% extends "base.html.twig" %}'];
        yield 'parent function' => ['{% extends "base.html.twig" %}{% block content %}{{ parent() }}{% endblock %}'];
        yield 'embed' => ['{% embed "base.html.twig" %}{% endembed %}'];
    }

    public function testRejectsAnUnknownStatementNodeEvenWithoutAnOutputMarker(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addTokenParser(new UnknownStatementTokenParser());

        $result = $this->createLinter($environment)->lint(new Source('{% unknown_statement %}{% unknown_statement %}', 'index.html.twig'));

        $this->assertSame([DiagnosticCode::UnsupportedNode, DiagnosticCode::UnsupportedNode], $this->getDiagnosticCodes($result));
    }

    #[DataProvider('provideIncompleteHtml')]
    public function testRequiresTheTemplateToEndInHtmlText(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::IncompleteHtmlContext], $this->getDiagnosticCodes($result));
    }

    public static function provideIncompleteHtml(): iterable
    {
        yield 'tag open' => ['<'];
        yield 'tag name' => ['<div'];
        yield 'quoted attribute' => ['<div title="value'];
        yield 'comment' => ['<!-- comment'];
        yield 'RCDATA' => ['<title>title'];
        yield 'raw text' => ['<script>script'];
        yield 'plaintext' => ['<plaintext>text'];
    }

    private function lint(string $template, string $name = 'index.html.twig', bool $force = false): AnalysisResult
    {
        return $this->createLinter(new Environment(new ArrayLoader(), ['optimizations' => 0]))->lint(new Source($template, $name), $force);
    }

    /**
     * @param array<string, string> $templates
     */
    private function lintTemplates(array $templates, string $name): AnalysisResult
    {
        $environment = new Environment(new ArrayLoader($templates), ['optimizations' => 0]);

        return $this->createLinter($environment)->lint($environment->getLoader()->getSourceContext($name));
    }

    private function createLinter(Environment $environment): ContextualEscapingLinter
    {
        return new ContextualEscapingLinter($environment, new ContextualEscapingAnalyzer(new HtmlContextParser(), new EnvironmentTemplateResolver($environment)));
    }

    /**
     * @return list<DiagnosticCode>
     */
    private function getDiagnosticCodes(AnalysisResult $result): array
    {
        return array_map(static fn ($diagnostic) => $diagnostic->getCode(), $result->getDiagnostics());
    }

    /**
     * @return list<list<EscapeOperation>>
     */
    private function getPlans(AnalysisResult $result): array
    {
        return array_map(static fn ($inferredEscape) => $inferredEscape->getPlan()->getOperations(), $result->getInferredEscapes());
    }
}

final class UnknownStatementTokenParser extends AbstractTokenParser
{
    public function parse(Token $token): Node
    {
        $this->parser->getStream()->expect(Token::BLOCK_END_TYPE);

        return new UnknownStatementNode([], [], $token->getLine());
    }

    public function getTag(): string
    {
        return 'unknown_statement';
    }
}

final class UnknownStatementNode extends Node
{
}
