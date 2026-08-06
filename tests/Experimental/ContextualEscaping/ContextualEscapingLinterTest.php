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
use Twig\Experimental\ContextualEscaping\EscapeOperation;
use Twig\Experimental\ContextualEscaping\HtmlContextParser;
use Twig\Loader\ArrayLoader;
use Twig\Node\Node;
use Twig\Source;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

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
        yield 'event handler' => ['<button onclick="{{ value }}">'];
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

    #[DataProvider('provideRawExpressions')]
    public function testRejectsRawOutput(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::RawOutput], $this->getDiagnosticCodes($result));
    }

    public static function provideRawExpressions(): iterable
    {
        yield 'direct raw filter' => ['{{ value|raw }}'];
        yield 'nested raw filter' => ['{{ condition ? value|raw : other }}'];
    }

    public function testCollectsEveryIndependentRawDiagnostic(): void
    {
        $result = $this->lint('{{ first|raw }}{{ second|raw }}');

        $this->assertSame([DiagnosticCode::RawOutput, DiagnosticCode::RawOutput], $this->getDiagnosticCodes($result));
    }

    public function testRejectsDisabledAutoescaping(): void
    {
        $result = $this->lint('{% autoescape false %}{{ value }}{% endautoescape %}');

        $this->assertSame([DiagnosticCode::DisabledAutoescaping], $this->getDiagnosticCodes($result));
    }

    public function testRejectsGeneratorOutput(): void
    {
        $environment = new Environment(new ArrayLoader(), ['autoescape' => false, 'optimizations' => 0]);
        $module = $environment->parse($environment->tokenize(new Source('{{ value }}', 'index.html.twig')));
        $module->getNode('body')->getNode(0)->getNode('expr')->setAttribute('is_generator', true);

        $result = (new ContextualEscapingAnalyzer(new HtmlContextParser()))->analyze($module);

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
    }

    #[DataProvider('provideMatchingExplicitEscapes')]
    public function testAcceptsMatchingExplicitEscaping(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
    }

    public static function provideMatchingExplicitEscapes(): iterable
    {
        yield 'HTML text' => ['{{ value|e("html") }}'];
        yield 'default escape strategy' => ['{{ value|e }}'];
        yield 'HTML attribute' => ['<div title="{{ value|e("html_attr") }}">'];
        yield 'HTML attribute autoescape block' => ['{% autoescape "html_attr" %}<div title="{{ value }}">{% endautoescape %}'];
        yield 'escape used as a function argument' => ['{{ max(value|e("js"), "fallback") }}'];
    }

    #[DataProvider('provideMismatchedExplicitEscapes')]
    public function testRejectsMismatchedExplicitEscaping(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::MismatchedExplicitEscaping], $this->getDiagnosticCodes($result));
    }

    public static function provideMismatchedExplicitEscapes(): iterable
    {
        yield 'wrong attribute strategy' => ['<div title="{{ value|e("html") }}">'];
        yield 'quoted strategy in an unquoted attribute' => ['<div title={{ value|e("html_attr") }}>'];
        yield 'quoted autoescape strategy in an unquoted attribute' => ['{% autoescape "html_attr" %}<div title={{ value }}>{% endautoescape %}'];
        yield 'dynamic strategy' => ['{{ value|e(strategy) }}'];
        yield 'conditional operand' => ['{{ condition ? value|e("js") : other }}'];
        yield 'autoescape block' => ['{% autoescape "html" %}<div title="{{ value }}">{% endautoescape %}'];
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
        yield 'include function in a condition' => ['{% if include("other.html.twig") %}content{% endif %}'];
        yield 'include function in an assignment' => ['{% set content = include("other.html.twig") %}'];
        yield 'include function in a do tag' => ['{% do include("other.html.twig") %}'];
        yield 'include function in a loop sequence' => ['{% for item in include("other.html.twig") %}{{ item }}{% endfor %}'];
        yield 'include function in a with expression' => ['{% with {content: include("other.html.twig")} %}content{% endwith %}'];
        yield 'import' => ['{% import "macros.html.twig" as macros %}'];
        yield 'from import' => ['{% from "macros.html.twig" import input %}'];
        yield 'block' => ['{% block content %}content{% endblock %}'];
        yield 'block function' => ['{{ block("content") }}{% block content %}content{% endblock %}'];
        yield 'macro' => ['{% macro field() %}field{% endmacro %}'];
        yield 'macro call' => ['{% macro field() %}field{% endmacro %}{{ _self.field() }}'];
        yield 'inheritance' => ['{% extends "base.html.twig" %}'];
        yield 'parent function' => ['{% extends "base.html.twig" %}{% block content %}{{ parent() }}{% endblock %}'];
        yield 'capture' => ['{% set content %}content{% endset %}'];
        yield 'embed' => ['{% embed "base.html.twig" %}{% endembed %}'];
    }

    /**
     * @param list<DiagnosticCode> $expectedCodes
     */
    #[DataProvider('provideUnsupportedCompositionContainingRawOutput')]
    public function testCollectsIndependentDiagnosticsInsideUnsupportedComposition(string $template, array $expectedCodes): void
    {
        $result = $this->lint($template);

        $this->assertSame($expectedCodes, $this->getDiagnosticCodes($result));
    }

    public static function provideUnsupportedCompositionContainingRawOutput(): iterable
    {
        $expectedCodes = [DiagnosticCode::UnsupportedTemplateComposition, DiagnosticCode::RawOutput];

        yield 'capture' => ['{% set content %}{{ value|raw }}{% endset %}', $expectedCodes];
        yield 'block' => ['{% block content %}{{ value|raw }}{% endblock %}', $expectedCodes];
        yield 'macro' => ['{% macro content() %}{{ value|raw }}{% endmacro %}', $expectedCodes];
        yield 'embed' => ['{% embed "base.html.twig" %}{% block content %}{{ value|raw }}{% endblock %}{% endembed %}', $expectedCodes];
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

    private function createLinter(Environment $environment): ContextualEscapingLinter
    {
        return new ContextualEscapingLinter($environment, new ContextualEscapingAnalyzer(new HtmlContextParser()));
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
