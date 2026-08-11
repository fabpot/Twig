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

class CssContextLinterTest extends AbstractLinterTestCase
{
    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideCssContexts
     */
    #[DataProvider('provideCssContexts')]
    public function testInfersPlansForCssContexts(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideCssContexts(): iterable
    {
        yield 'property value' => [
            '<style>.notice { color: {{ value }}; }</style>',
            [[EscapeOperation::CssValue]],
        ];
        yield 'case-insensitive style element' => [
            '<STYLE>.notice { color: {{ value }}; }</STYLE>',
            [[EscapeOperation::CssValue]],
        ];
        yield 'self-closing style syntax' => [
            '<style/>.notice { color: {{ value }}; }</style>',
            [[EscapeOperation::CssValue]],
        ];
        yield 'style end tag inside a CSS string' => [
            '<style>.notice::after { content: "</style>{{ value }}',
            [[EscapeOperation::HtmlText]],
        ];
        yield 'similar style end tag inside a CSS string' => [
            '<style>.notice::after { content: "</stylex>{{ value }}"; }</style>',
            [[EscapeOperation::CssString]],
        ];
        yield 'nested function value' => [
            '<style>.notice { color: rgb({{ red }}, {{ green }}, {{ blue }}); }</style>',
            [[EscapeOperation::CssValue], [EscapeOperation::CssValue], [EscapeOperation::CssValue]],
        ];
        yield 'nested media rule value' => [
            '<style>@media screen { .notice { color: {{ value }}; } }</style>',
            [[EscapeOperation::CssValue]],
        ];
        yield 'double-quoted string' => [
            '<style>.notice::after { content: "{{ value }}"; }</style>',
            [[EscapeOperation::CssString]],
        ];
        yield 'single-quoted string' => [
            "<style>.notice::after { content: '{{ value }}'; }</style>",
            [[EscapeOperation::CssString]],
        ];
        yield 'string after a terminated CSS escape' => [
            '<style>.notice::after { content: "\\61 {{ value }}"; }</style>',
            [[EscapeOperation::CssString]],
        ];
        yield 'string after a six-digit CSS escape' => [
            '<style>.notice::after { content: "\\000061{{ value }}"; }</style>',
            [[EscapeOperation::CssString]],
        ];
        yield 'quoted style attribute value' => [
            '<div style="color: {{ value }}">',
            [[EscapeOperation::CssValue, EscapeOperation::HtmlAttribute]],
        ];
        yield 'single-quoted style attribute value' => [
            "<div style='color: {{ value }}'>",
            [[EscapeOperation::CssValue, EscapeOperation::HtmlAttribute]],
        ];
        yield 'unquoted style attribute value' => [
            '<div style=color:{{ value }}>',
            [[EscapeOperation::CssValue, EscapeOperation::HtmlAttributeUnquoted]],
        ];
        yield 'style attribute string' => [
            "<div style=\"content: '{{ value }}'\">",
            [[EscapeOperation::CssString, EscapeOperation::HtmlAttribute]],
        ];
        yield 'style attribute remains a declaration list after a closing brace' => [
            '<div style="color: red; } width: {{ value }}">',
            [[EscapeOperation::CssValue, EscapeOperation::HtmlAttribute]],
        ];
        yield 'complete unquoted CSS URL' => [
            '<style>.notice { background: url({{ value }}); }</style>',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::CssString]],
        ];
        yield 'complete double-quoted CSS URL' => [
            '<style>.notice { background: url("{{ value }}"); }</style>',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::CssString]],
        ];
        yield 'complete single-quoted CSS URL' => [
            "<style>.notice { background: url('{{ value }}'); }</style>",
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::CssString]],
        ];
        yield 'CSS URL path' => [
            '<style>.notice { background: url(/images/{{ value }}); }</style>',
            [[EscapeOperation::UrlPath, EscapeOperation::CssString]],
        ];
        yield 'CSS URL query' => [
            '<style>.notice { background: url(/image?id={{ value }}); }</style>',
            [[EscapeOperation::UrlQuery, EscapeOperation::CssString]],
        ];
        yield 'CSS URL in a style attribute' => [
            '<div style="background: url({{ value }})">',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::CssString, EscapeOperation::HtmlAttribute]],
        ];
        yield 'top-level import URL' => [
            '<style>@import url({{ value }});</style>',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::CssString]],
        ];
        yield 'double-quoted import URL' => [
            '<style>@import "{{ value }}" screen;</style>',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::CssString]],
        ];
        yield 'single-quoted import URL' => [
            "<style>@IMPORT '{{ value }}';</style>",
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::CssString]],
        ];
        yield 'explicit CSS string inside a string' => [
            "<style>.notice::after { content: \"{{ value|e('css') }}\"; }</style>",
            [[]],
        ];
        yield 'explicit CSS string used as a value' => [
            '<style>.notice { color: {{ value|e("css") }}; }</style>',
            [[EscapeOperation::CssValue]],
        ];
        yield 'explicit URL component at a CSS URL start' => [
            '<style>.notice { background: url({{ value|e("url") }}/suffix); }</style>',
            [[EscapeOperation::CssString]],
        ];
        yield 'trusted CSS value' => [
            '<style>.notice { color: {{ value|raw }}; }</style>',
            [[]],
        ];
    }

    /**
     * @dataProvider provideStructuralCssContexts
     */
    #[DataProvider('provideStructuralCssContexts')]
    public function testRejectsStructuralCssContexts(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
    }

    public static function provideStructuralCssContexts(): iterable
    {
        yield 'selector' => ['<style>{{ value }} { color: red; }</style>'];
        yield 'property name' => ['<style>.notice { {{ value }}: red; }</style>'];
        yield 'import target' => ['<style>@import {{ value }};</style>'];
        yield 'whole style attribute' => ['<div style="{{ value }}">'];
        yield 'CSS-escaped whole style attribute' => ['<div style="{{ value|e("css") }}">'];
    }

    /**
     * @dataProvider provideCssComments
     */
    #[DataProvider('provideCssComments')]
    public function testRejectsOutputInsideCssComments(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::CssCommentInterpolation], $this->getDiagnosticCodes($result));
    }

    public static function provideCssComments(): iterable
    {
        yield 'stylesheet comment' => ['<style>/* {{ value }} */</style>'];
        yield 'property comment' => ['<style>.notice { color: red; /* {{ value }} */ }</style>'];
        yield 'style attribute comment' => ['<div style="color: red; /* {{ value }} */">'];
    }

    /**
     * @dataProvider provideAmbiguousCss
     */
    #[DataProvider('provideAmbiguousCss')]
    public function testRejectsAmbiguousCssContexts(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::AmbiguousCssContext], $this->getDiagnosticCodes($result));
    }

    public static function provideAmbiguousCss(): iterable
    {
        yield 'partial value token' => ['<style>.notice { color: red{{ value }}; }</style>'];
        yield 'partial property token' => ['<style>.notice { col{{ value }}: red; }</style>'];
        yield 'escaped position' => ['<style>.notice { color: \\{{ value }}; }</style>'];
        yield 'between a URL value and closing parenthesis' => ['<style>.notice { background: url("image" {{ value }}); }</style>'];
        yield 'invalid unquoted URL' => ['<style>.notice { background: url(image"{{ value }}); }</style>'];
        yield 'escaped URL function' => ['<style>.notice { background: \\75rl({{ value }}); }</style>'];
        yield 'escaped import keyword' => ['<style>@\\69mport "{{ value }}";</style>'];
        yield 'style attribute character reference' => ['<div style="content: &quot;{{ value }}&quot;">'];
    }

    public function testRejectsCssBranchesEndingInDifferentLexicalContexts(): void
    {
        $result = $this->lint('<style>.notice { content: {% if condition %}"string{% endif %}{{ value }}; }</style>');

        $this->assertSame([DiagnosticCode::AmbiguousControlFlow], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('CSS DoubleQuotedString', $result->getDiagnostics()[0]->getMessage());
        $this->assertStringContainsString('CSS Value', $result->getDiagnostics()[0]->getMessage());
    }

    public function testRejectsOutputAfterAnAmbiguousCssUrl(): void
    {
        $result = $this->lint('<style>.notice { background: url({{ first }}/{{ second }}); }</style>');

        $this->assertSame([DiagnosticCode::AmbiguousUrlContext], $this->getDiagnosticCodes($result));
        $this->assertSame([[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::CssString]], $this->getPlans($result));
    }

    public function testTreatsACssEscapeBeforeAUrlInterpolationAsAmbiguous(): void
    {
        $result = $this->lint('<style>.notice { background: url(image\\61 {{ value }}); }</style>');

        $this->assertSame([DiagnosticCode::AmbiguousUrlContext], $this->getDiagnosticCodes($result));
        $this->assertSame([], $result->getInferredEscapes());
    }
}
