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
use Twig\Extra\ContextualEscaping\Analysis\DiagnosticCode;
use Twig\Extra\ContextualEscaping\Analysis\EscapeOperation;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFunction;

class JavaScriptContextLinterTest extends AbstractLinterTestCase
{
    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideJavaScriptContexts
     */
    #[DataProvider('provideJavaScriptContexts')]
    public function testInfersPlansForJavaScriptContexts(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideJavaScriptContexts(): iterable
    {
        yield 'script value' => [
            '<script>const value = {{ value }};</script>',
            [[EscapeOperation::JavaScriptValue]],
        ];
        yield 'self-closing script syntax' => [
            '<script/>{{ value }}</script>',
            [[EscapeOperation::JavaScriptValue]],
        ];
        yield 'double-quoted string' => [
            '<script>const value = "{{ value }}";</script>',
            [[EscapeOperation::JavaScriptString]],
        ];
        yield 'single-quoted string' => [
            "<script>const value = '{{ value }}';</script>",
            [[EscapeOperation::JavaScriptString]],
        ];
        yield 'template string' => [
            '<script>const value = `{{ value }}`;</script>',
            [[EscapeOperation::JavaScriptTemplateString]],
        ];
        yield 'template string expression' => [
            '<script>const value = `${other + {{ value }}}`;</script>',
            [[EscapeOperation::JavaScriptValue]],
        ];
        yield 'nested template string' => [
            '<script>const value = `${`nested {{ value }}`}`;</script>',
            [[EscapeOperation::JavaScriptTemplateString]],
        ];
        yield 'regular expression' => [
            '<script>const value = /prefix{{ value }}suffix/g;</script>',
            [[EscapeOperation::JavaScriptRegExp]],
        ];
        yield 'regular expression character class' => [
            '<script>const value = /[{{ value }}]/g;</script>',
            [[EscapeOperation::JavaScriptRegExp]],
        ];
        yield 'regular expression after return' => [
            '<script>function value() { return /prefix{{ value }}/; }</script>',
            [[EscapeOperation::JavaScriptRegExp]],
        ];
        yield 'division operand' => [
            '<script>const result = other / {{ value }};</script>',
            [[EscapeOperation::JavaScriptValue]],
        ];
        yield 'subtraction operand without whitespace' => [
            '<script>const result = other-{{ value }};</script>',
            [[EscapeOperation::JavaScriptValue]],
        ];
        yield 'division after postfix increment' => [
            '<script>const result = count++ / {{ value }};</script>',
            [[EscapeOperation::JavaScriptValue]],
        ];
        yield 'division after postfix decrement' => [
            '<script>const result = count-- / {{ value }};</script>',
            [[EscapeOperation::JavaScriptValue]],
        ];
        yield 'division after a serialized value' => [
            '<script>const result = {{ first }}/{{ second }};</script>',
            [[EscapeOperation::JavaScriptValue], [EscapeOperation::JavaScriptValue]],
        ];
        yield 'value after a less-than operator' => [
            '<script>const result = first < second ? {{ value }} : null;</script>',
            [[EscapeOperation::JavaScriptValue]],
        ];
        yield 'quoted event handler value' => [
            '<button onclick="handle({{ value }})">',
            [[EscapeOperation::JavaScriptValue, EscapeOperation::HtmlAttribute]],
        ];
        yield 'event handler string' => [
            "<button onclick=\"handle('{{ value }}')\">",
            [[EscapeOperation::JavaScriptString, EscapeOperation::HtmlAttribute]],
        ];
        yield 'unquoted event handler value' => [
            '<button onclick=handle({{ value }})>',
            [[EscapeOperation::JavaScriptValue, EscapeOperation::HtmlAttributeUnquoted]],
        ];
        yield 'explicit JavaScript string inside a string' => [
            "<button onclick=\"handle('{{ value|e('js') }}')\">",
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'explicit JavaScript string used as a value' => [
            '<button onclick="handle({{ value|e("js") }})">',
            [[EscapeOperation::JavaScriptValue, EscapeOperation::HtmlAttribute]],
        ];
        yield 'trusted event handler expression' => [
            '<button onclick="{{ value|raw }}">',
            [[EscapeOperation::HtmlAttribute]],
        ];
    }

    public function testUsesDeclaredJavaScriptTypes(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('safe_js', static fn () => '', ['is_safe' => ['js']]));
        $environment->addFunction(new TwigFunction('safe_js_string', static fn () => '', ['is_safe' => ['js_string']]));

        $result = $this->createLinter($environment)->lint(new Source('<script>{{ safe_js() }}</script><button onclick="{{ safe_js() }}"><button onclick="value=\'{{ safe_js_string() }}\'">', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([
            [],
            [EscapeOperation::HtmlAttribute],
            [EscapeOperation::HtmlAttribute],
        ], $this->getPlans($result));
    }

    public function testTreatsSlashAfterTrustedJavaScriptAsAmbiguous(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('safe_js', static fn () => '', ['is_safe' => ['js']]));

        $result = $this->createLinter($environment)->lint(new Source('<script>{{ safe_js() }}/{{ value }}</script>', 'index.html.twig'));

        $this->assertSame([DiagnosticCode::AmbiguousJavaScriptContext], $this->getDiagnosticCodes($result));
        $this->assertSame([[]], $this->getPlans($result));
    }

    /**
     * @dataProvider provideJavaScriptComments
     */
    #[DataProvider('provideJavaScriptComments')]
    public function testRejectsOutputInsideJavaScriptComments(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::JavaScriptCommentInterpolation], $this->getDiagnosticCodes($result));
    }

    public static function provideJavaScriptComments(): iterable
    {
        yield 'line comment' => ["<script>// {{ value }}\n</script>"];
        yield 'block comment' => ['<script>/* {{ value }} */</script>'];
        yield 'HTML-like open comment' => ['<script><!-- {{ value }}\n</script>'];
        yield 'HTML-like close comment' => ['<script>--> {{ value }}\n</script>'];
        yield 'HTML double-escaped script data' => ['<script><!-- <script> </script> {{ value }}</script>'];
    }

    /**
     * @dataProvider provideAmbiguousJavaScript
     */
    #[DataProvider('provideAmbiguousJavaScript')]
    public function testRejectsAmbiguousJavaScriptContexts(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::AmbiguousJavaScriptContext], $this->getDiagnosticCodes($result));
    }

    public static function provideAmbiguousJavaScript(): iterable
    {
        yield 'identifier token' => ['<script>identifier{{ value }}</script>'];
        yield 'unknown slash after a closing brace' => ['<script>{}/{{ value }}/</script>'];
        yield 'slash after a closing parenthesis' => ['<script>if (ready) /{{ value }}/.test(input);</script>'];
        yield 'escaped string position' => ['<script>"\\{{ value }}"</script>'];
        yield 'template interpolation candidate' => ['<script>`${{ value }}`</script>'];
        yield 'event-handler character reference' => ['<button onclick="value=&quot;{{ value }}&quot;">'];
    }

    public function testRejectsJavaScriptBranchesEndingInDifferentLexicalContexts(): void
    {
        $result = $this->lint('<script>{% if condition %}"string{% endif %}{{ value }}</script>');

        $this->assertSame([DiagnosticCode::AmbiguousControlFlow], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('JavaScript DoubleQuotedString', $result->getDiagnostics()[0]->getMessage());
        $this->assertStringContainsString('JavaScript Code', $result->getDiagnostics()[0]->getMessage());
    }
}
