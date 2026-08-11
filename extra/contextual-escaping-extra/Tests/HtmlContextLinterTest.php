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
use Twig\Extra\ContextualEscaping\Analysis\DiagnosticCode;
use Twig\Extra\ContextualEscaping\Analysis\EscapeOperation;
use Twig\Extra\ContextualEscaping\Analysis\FiniteStaticValueSet;

class HtmlContextLinterTest extends AbstractLinterTestCase
{
    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideSupportedContexts
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

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::JavaScriptValue]], $this->getPlans($result));
    }

    public function testTracksFiniteStaticValuesThroughAssignments(): void
    {
        $result = $this->lint(<<<'TWIG'
            <style>
                {% set colors = [['#fff', '#000'], ['#abc', '#def']] %}
                {% set color = random(colors) %}
                {% set light = color|first %}
                .example { color: {{ light }}; }
            </style>
            TWIG);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[]], $this->getPlans($result));
        $inferredEscape = $result->getInferredEscapes()[0];
        $this->assertSame(['#fff', '#abc'], $inferredEscape->getStaticOutputs());
        $this->assertSame([
            'light',
            'color|first',
            'color',
            'random(colors)',
            'colors',
            'fixed local array',
        ], $inferredEscape->getProvenance());
        $this->assertSame('CSS Value', $inferredEscape->getContext());
    }

    /**
     * @param list<string> $outputs
     *
     * @dataProvider provideFiniteStaticExpressions
     */
    #[DataProvider('provideFiniteStaticExpressions')]
    public function testTracksFiniteStaticExpressions(string $assignment, array $outputs): void
    {
        $result = $this->lint('{% set values = [["a", "b"], ["c", "d"]] %}{% set selected = random(values) %}{% set value = '.$assignment.' %}{{ value }}');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[]], $this->getPlans($result));
        $this->assertSame($outputs, $result->getInferredEscapes()[0]->getStaticOutputs());
    }

    public static function provideFiniteStaticExpressions(): iterable
    {
        yield 'constant index' => ['selected[1]', ['b', 'd']];
        yield 'map lookup' => ['{"name": "mapped"}["name"]', ['mapped']];
        yield 'last filter' => ['selected|last', ['b', 'd']];
        yield 'conditional branches' => ['condition ? "yes" : "no"', ['yes', 'no']];
        yield 'concatenation' => ['selected[0] ~ selected[1]', ['ab', 'ad', 'cb', 'cd']];
        yield 'merged arrays' => ['random(values|merge([["e", "f"]]))|first', ['a', 'c', 'e']];
    }

    public function testAppliesConfiguredEscapingToFiniteStaticOutputs(): void
    {
        $result = $this->lint('{% set value = "<" %}{{ value }}');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[]], $this->getPlans($result));
        $this->assertSame(['&lt;'], $result->getInferredEscapes()[0]->getStaticOutputs());
    }

    public function testMergesFiniteStaticAssignmentsAcrossBranches(): void
    {
        $result = $this->lint('<style>{% if condition %}{% set color = "#fff" %}{% else %}{% set color = "#000" %}{% endif %}.example { color: {{ color }}; }</style>');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[]], $this->getPlans($result));
        $this->assertSame(['#fff', '#000'], $result->getInferredEscapes()[0]->getStaticOutputs());
    }

    public function testDropsFiniteStaticAssignmentWhenABranchIsDynamic(): void
    {
        $result = $this->lint('<style>{% if condition %}{% set color = "#fff" %}{% else %}{% set color = dynamic %}{% endif %}.example { color: {{ color }}; }</style>');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::CssValue]], $this->getPlans($result));
    }

    public function testRejectsFiniteStaticOutputsThatEndInDifferentContexts(): void
    {
        $result = $this->lint('<style>.example { color: {% set values = ["u", "x"] %}{{ random(values) }}; }</style>');

        $this->assertSame([DiagnosticCode::AmbiguousControlFlow], $this->getDiagnosticCodes($result));
        $this->assertSame([], $result->getInferredEscapes());
    }

    public function testDoesNotReuseAStaticValueShadowedByALoopTarget(): void
    {
        $result = $this->lint('<style>{% set color = "#fff" %}{% for color in colors %}.example { color: {{ color }}; }{% endfor %}</style>');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::CssValue]], $this->getPlans($result));
    }

    public function testDoesNotReuseAContentTypeShadowedByALoopTarget(): void
    {
        $result = $this->lint('{% set url = "/a"|e("url") %}{% for url in urls %}<a href="{{ url }}">x</a>{% endfor %}');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]], $this->getPlans($result));
    }

    public function testLimitsFiniteStaticValues(): void
    {
        $values = implode(', ', array_map(static fn (int $value): string => '"'.$value.'"', range(0, FiniteStaticValueSet::MAX_VALUES)));
        $result = $this->lint('{% set values = ['.$values.'] %}{{ random(values) }}');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }

    /**
     * @dataProvider provideUnsupportedAttributes
     */
    #[DataProvider('provideUnsupportedAttributes')]
    public function testRejectsAttributesRequiringLanguageSpecificAnalysis(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::UnsupportedAttributeContext], $this->getDiagnosticCodes($result));
    }

    public static function provideUnsupportedAttributes(): iterable
    {
        yield 'URL list' => ['<a ping="{{ value }}">'];
        yield 'embedded HTML' => ['<iframe srcdoc="{{ value }}"></iframe>'];
    }

    public function testCollectsIndependentDiagnosticsFromEveryBranch(): void
    {
        $result = $this->lint('{% if a %}<!-- {{ first }} -->{% else %}<div style="{{ second }}"></div>{% endif %}');

        $this->assertSame([
            DiagnosticCode::CommentInterpolation,
            DiagnosticCode::UnsupportedOutputContext,
        ], $this->getDiagnosticCodes($result));
    }

    public function testDiagnosticsRetainTheTemplateLocation(): void
    {
        $result = $this->lint("<p>text</p>\n<div style=\"{{ value }}\">content</div>", 'page.html.twig');
        $diagnostic = $result->getDiagnostics()[0];

        $this->assertSame(2, $diagnostic->getTemplateLine());
        $this->assertSame('page.html.twig', $diagnostic->getTemplateName());
    }

    public function testRejectsCommentInterpolation(): void
    {
        $result = $this->lint('<!-- {{ value }} -->');

        $this->assertSame([DiagnosticCode::CommentInterpolation], $this->getDiagnosticCodes($result));
    }

    /**
     * @dataProvider provideUnsupportedRawTextContexts
     */
    #[DataProvider('provideUnsupportedRawTextContexts')]
    public function testRejectsOutputInRawText(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
    }

    public static function provideUnsupportedRawTextContexts(): iterable
    {
        yield 'style' => ['<style>{{ value }}</style>'];
        yield 'legacy raw-text element' => ['<xmp>{{ value }}</xmp>'];
        yield 'iframe fallback content' => ['<iframe>{{ value }}</iframe>'];
    }

    public function testCollectsEveryUnsupportedOutputContextDiagnostic(): void
    {
        $result = $this->lint('<style>{{ first }}{{ second }}</style>');

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext, DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
    }

    /**
     * @dataProvider provideStructuralInterpolation
     */
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

    /**
     * @dataProvider provideConditions
     */
    #[DataProvider('provideConditions')]
    public function testRejectsIncompatibleIfBranches(string $condition): void
    {
        $result = $this->lint(\sprintf('{%% if %s %%}<script>{%% endif %%}{{ value }}', $condition));

        $this->assertSame([DiagnosticCode::AmbiguousControlFlow], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('JavaScript Code and HTML text', $result->getDiagnostics()[0]->getMessage());
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

    public function testReusesCurrentSafetyAnalysisForConstantConditionalOutput(): void
    {
        $result = $this->lint('<div class="{{ enabled ? "wide" : "" }}"><a href="{{ enabled ? "/one/" : "/two/" }}{{ value }}">');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([
            [],
            [],
            [EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute],
        ], $this->getPlans($result));
    }

    public function testRejectsConstantConditionalOutputEndingInDifferentContexts(): void
    {
        $result = $this->lint('<a href="{{ enabled ? "/path/" : "?query=" }}{{ value }}">');

        $this->assertSame([DiagnosticCode::AmbiguousControlFlow], $this->getDiagnosticCodes($result));
        $this->assertSame([[]], $this->getPlans($result));
    }

    /**
     * @dataProvider provideIncompleteHtml
     */
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
}
