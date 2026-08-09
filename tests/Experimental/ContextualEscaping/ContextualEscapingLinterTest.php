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
use Twig\Attribute\YieldReady;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Experimental\ContextualEscaping\AnalysisResult;
use Twig\Experimental\ContextualEscaping\ContextualEscapingAnalyzer;
use Twig\Experimental\ContextualEscaping\ContextualEscapingLinter;
use Twig\Experimental\ContextualEscaping\CssContextParser;
use Twig\Experimental\ContextualEscaping\DiagnosticCode;
use Twig\Experimental\ContextualEscaping\EscapeOperation;
use Twig\Experimental\ContextualEscaping\HtmlContextParser;
use Twig\Experimental\ContextualEscaping\JavaScriptContextParser;
use Twig\Experimental\ContextualEscaping\MetaRefreshContextParser;
use Twig\Experimental\ContextualEscaping\SrcsetContextParser;
use Twig\Extension\ProfilerExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Node\Node;
use Twig\Profiler\Profile;
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

    public function testCanLintATemplateByLoaderName(): void
    {
        $environment = new Environment(new ArrayLoader(['index.html.twig' => '{{ value }}']), ['optimizations' => 0]);

        $result = ContextualEscapingLinter::create($environment)->lintTemplate('index.html.twig');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }

    public function testIgnoresProfilerInstrumentation(): void
    {
        $environment = new Environment(new ArrayLoader(['index.html.twig' => '<p>{{ value }}</p>']), ['optimizations' => 0]);
        $environment->addExtension(new ProfilerExtension(new Profile()));

        $result = ContextualEscapingLinter::create($environment)->lintTemplate('index.html.twig');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }

    public function testCanLintADirectory(): void
    {
        $directory = __DIR__.'/Fixtures/directory';
        $environment = new Environment(new FilesystemLoader($directory), ['optimizations' => 0]);

        $results = iterator_to_array(ContextualEscapingLinter::create($environment)->lintDirectory($directory));

        $this->assertSame([
            'deprecated.html.twig',
            'first.html.twig',
            'nested/second.html.twig',
            'syntax-error.html.twig',
        ], array_keys($results));
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($results['first.html.twig']));
        $this->assertSame([
            [EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute],
            [EscapeOperation::HtmlText],
        ], $this->getPlans($results['nested/second.html.twig']));
        $this->assertSame([DiagnosticCode::SyntaxError], $this->getDiagnosticCodes($results['deprecated.html.twig']));
        $this->assertSame([DiagnosticCode::SyntaxError], $this->getDiagnosticCodes($results['syntax-error.html.twig']));
        $this->assertSame('syntax-error.html.twig', $results['syntax-error.html.twig']->getDiagnostics()[0]->getTemplateName());
    }

    public function testCanLintANamespacedDirectoryWithACustomExtension(): void
    {
        $directory = __DIR__.'/Fixtures/directory';
        $loader = new FilesystemLoader();
        $loader->addPath($directory, 'scripts');
        $environment = new Environment($loader, ['optimizations' => 0]);

        $results = iterator_to_array(ContextualEscapingLinter::create($environment)->lintDirectory($directory, 'scripts', '.js.twig'));

        $this->assertSame(['@scripts/ignored.js.twig'], array_keys($results));
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($results['@scripts/ignored.js.twig']));
        $inferredEscape = $results['@scripts/ignored.js.twig']->getInferredEscapes()[0];
        $this->assertSame('@scripts/ignored.js.twig', $inferredEscape->getNode()->getTemplateName());
    }

    public function testRejectsANonexistentLintDirectory(): void
    {
        $linter = ContextualEscapingLinter::create(new Environment(new ArrayLoader()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "missing" directory does not exist.');

        iterator_to_array($linter->lintDirectory('missing'));
    }

    public function testRejectsDeprecatedTemplateSyntax(): void
    {
        try {
            $this->lint("\n{% macro greet() %}{% endmacro %}{{ _self.greet }}");
        } catch (SyntaxError $error) {
            $this->assertStringContainsString('Contextual escaping analysis only supports templates without deprecations', $error->getMessage());
            $this->assertSame(2, $error->getTemplateLine());
            $this->assertSame('index.html.twig', $error->getSourceContext()?->getName());

            return;
        }

        $this->fail('A deprecated template was not rejected.');
    }

    public function testDelegatesNonTemplateDeprecationsToThePreviousHandler(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('deprecated_extension', static fn () => '', [
            'is_safe_callback' => static function (Node $arguments): array {
                trigger_deprecation('twig/twig', '3.29', 'The custom extension is deprecated.');

                return [];
            },
        ]));
        $deprecations = [];
        set_error_handler(static function (int $type, string $message) use (&$deprecations): bool {
            if (\E_USER_DEPRECATED === $type) {
                $deprecations[] = $message;

                return true;
            }

            return false;
        });

        try {
            $result = $this->createLinter($environment)->lint(new Source('{{ deprecated_extension() }}', 'index.html.twig'));
        } finally {
            restore_error_handler();
        }

        $this->assertSame([
            'Since twig/twig 3.29: The custom extension is deprecated.',
            'Since twig/twig 3.29: The custom extension is deprecated.',
            'Since twig/twig 3.29: The custom extension is deprecated.',
        ], $deprecations);
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }

    public function testCanReuseTheLinter(): void
    {
        $linter = $this->createLinter(new Environment(new ArrayLoader(), ['optimizations' => 0]));

        $invalid = $linter->lint(new Source('<div style="{{ value }}">', 'first.html.twig'));
        $valid = $linter->lint(new Source('<p>{{ value }}</p>', 'second.html.twig'));

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($invalid));
        $this->assertSame([], $valid->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($valid));
    }

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

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideSrcsetContexts
     */
    #[DataProvider('provideSrcsetContexts')]
    public function testInfersPlansForSrcsetContexts(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideSrcsetContexts(): iterable
    {
        yield 'complete srcset' => [
            '<img srcset="{{ value }}">',
            [[EscapeOperation::SrcsetFilter, EscapeOperation::HtmlAttribute]],
        ];
        yield 'link image srcset' => [
            '<link imagesrcset="{{ value }}">',
            [[EscapeOperation::SrcsetFilter, EscapeOperation::HtmlAttribute]],
        ];
        yield 'unquoted complete srcset' => [
            '<img srcset={{ value }}>',
            [[EscapeOperation::SrcsetFilter, EscapeOperation::HtmlAttributeUnquoted]],
        ];
        yield 'leading separators' => [
            '<img srcset=",, {{ value }}">',
            [[EscapeOperation::SrcsetFilter, EscapeOperation::HtmlAttribute]],
        ];
        yield 'multiple candidates' => [
            '<img srcset="{{ first }} 1x, {{ second }} 2x">',
            [
                [EscapeOperation::SrcsetFilter, EscapeOperation::HtmlAttribute],
                [EscapeOperation::SrcsetFilter, EscapeOperation::HtmlAttribute],
            ],
        ];
        yield 'URL path' => [
            '<img srcset="/images/{{ name }}.png 1x">',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
        yield 'URL query' => [
            '<img srcset="/image.php?id={{ id }} 2x">',
            [[EscapeOperation::UrlQuery, EscapeOperation::HtmlAttribute]],
        ];
        yield 'data URL comma' => [
            '<img srcset="data:image/png;base64,AAAA 1x, {{ value }} 2x">',
            [[EscapeOperation::SrcsetFilter, EscapeOperation::HtmlAttribute]],
        ];
        yield 'data URL payload' => [
            '<img srcset="data:image/png;base64,AA{{ value }}AA 1x">',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
        yield 'URL component followed by a path segment' => [
            '<img srcset="{{ first|e("url") }}/{{ second }} 2x">',
            [
                [EscapeOperation::HtmlAttribute],
                [EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute],
            ],
        ];
        yield 'explicit srcset escaping' => [
            '<img srcset="{{ value|e("srcset") }}">',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'branches ending in URL paths' => [
            '<img srcset="{% if condition %}/first/{% else %}/second/{% endif %}{{ value }} 1x">',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
        yield 'trusted descriptor' => [
            '<img srcset="/image.png {{ descriptor|raw }}">',
            [[EscapeOperation::HtmlAttribute]],
        ];
    }

    public function testUsesDeclaredTypesInSrcsetCandidates(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('safe_srcset', static fn () => '', ['is_safe' => ['srcset']]));
        $environment->addFunction(new TwigFunction('safe_url', static fn () => '', ['is_safe' => ['url']]));

        $result = $this->createLinter($environment)->lint(new Source('<img srcset="{{ safe_srcset() }}"><img srcset="{{ safe_url() }} 1x, {{ value }} 2x">', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([
            [EscapeOperation::HtmlAttribute],
            [EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute],
            [EscapeOperation::SrcsetFilter, EscapeOperation::HtmlAttribute],
        ], $this->getPlans($result));
    }

    public function testRejectsDeclaredTypesInAmbiguousSrcsetPositions(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('safe_srcset', static fn () => '', ['is_safe' => ['srcset']]));
        $environment->addFunction(new TwigFunction('safe_url', static fn () => '', ['is_safe' => ['url']]));

        $result = $this->createLinter($environment)->lint(new Source('<img srcset="/prefix/{{ safe_srcset() }}"><img srcset="{{ safe_url() }}{{ value }}">', 'index.html.twig'));

        $this->assertSame([
            DiagnosticCode::AmbiguousSrcsetContext,
            DiagnosticCode::AmbiguousSrcsetContext,
        ], $this->getDiagnosticCodes($result));
        $this->assertSame([
            [EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute],
        ], $this->getPlans($result));
    }

    /**
     * @dataProvider provideAmbiguousSrcsetContexts
     */
    #[DataProvider('provideAmbiguousSrcsetContexts')]
    public function testRejectsAmbiguousSrcsetContexts(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::AmbiguousSrcsetContext], $this->getDiagnosticCodes($result));
    }

    public static function provideAmbiguousSrcsetContexts(): iterable
    {
        yield 'comma in URL' => ['<img srcset="image.png,{{ value }}">'];
        yield 'descriptor start' => ['<img srcset="image.png {{ value }}">'];
        yield 'partial descriptor' => ['<img srcset="image.png 1{{ value }}x">'];
        yield 'parenthesized descriptor' => ['<img srcset="image.png ({{ value }})">'];
        yield 'character reference' => ['<img srcset="image&amp;{{ value }}">'];
        yield 'adjacent complete values' => ['<img srcset="{{ first }}{{ second }}">'];
    }

    public function testRejectsSrcsetBranchesEndingInDifferentContexts(): void
    {
        $result = $this->lint('<img srcset="{% if condition %}/image/{% else %}/image.png 1x, {% endif %}{{ value }}">');

        $this->assertSame([DiagnosticCode::AmbiguousControlFlow], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('srcset URL path', $result->getDiagnostics()[0]->getMessage());
        $this->assertStringContainsString('srcset candidate start', $result->getDiagnostics()[0]->getMessage());
    }

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideUrlContexts
     */
    #[DataProvider('provideUrlContexts')]
    public function testInfersPlansForUrlContexts(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideUrlContexts(): iterable
    {
        yield 'complete URL' => [
            '<a href="{{ value }}">',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'HTML-escaped complete URL' => [
            '<a href="{{ value|e("html_attr") }}">',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'case-insensitive URL attribute' => [
            '<a HREF="{{ value }}">',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'namespaced URL attribute' => [
            '<a svg:href="{{ value }}">',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'custom data URL attribute' => [
            '<div data-url="{{ value }}">',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'custom source URL attribute' => [
            '<div image-src="{{ value }}">',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'unquoted complete URL' => [
            '<a href={{ value }}>',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttributeUnquoted]],
        ];
        yield 'complete URL after leading control and space characters' => [
            "<a href=\"\t \n{{ value }}\">",
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'path segment' => [
            '<a href="/users/{{ value }}">',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
        yield 'multiple path segments' => [
            '<a href="/{{ first }}/{{ second }}">',
            [
                [EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute],
                [EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute],
            ],
        ];
        yield 'static URL expression followed by a path segment' => [
            '<a href="{{ "https://example.com/" }}{{ value }}">',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
        yield 'query value' => [
            '<a href="/search?q={{ value }}">',
            [[EscapeOperation::UrlQuery, EscapeOperation::HtmlAttribute]],
        ];
        yield 'fragment value' => [
            '<a href="#{{ value }}">',
            [[EscapeOperation::UrlQuery, EscapeOperation::HtmlAttribute]],
        ];
        yield 'URL-escaped query value' => [
            '<a href="/search?q={{ value|e("url") }}">',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'URL component followed by a path segment' => [
            '<a href="{{ first|e("url") }}/{{ second }}">',
            [
                [EscapeOperation::HtmlAttribute],
                [EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute],
            ],
        ];
        yield 'dynamic URL followed by a static query' => [
            '<a href="{{ base }}?q={{ value }}">',
            [
                [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute],
                [EscapeOperation::UrlQuery, EscapeOperation::HtmlAttribute],
            ],
        ];
        yield 'branches ending in URL paths' => [
            '<a href="{% if condition %}/first/{% else %}/second/{% endif %}{{ value }}">',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
    }

    public function testUsesDeclaredUrlTypesAtTheCorrectUrlPart(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('safe_url', static fn () => '', ['is_safe' => ['url']]));

        $result = $this->createLinter($environment)->lint(new Source('<a href="{{ safe_url() }}"><a href="/prefix/{{ safe_url() }}"><a href="?next={{ safe_url() }}"><meta http-equiv="refresh" content="0;url={{ safe_url() }}">', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([
            [EscapeOperation::HtmlAttribute],
            [EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute],
            [EscapeOperation::UrlQuery, EscapeOperation::HtmlAttribute],
            [EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute],
        ], $this->getPlans($result));
    }

    public function testRejectsAmbiguousUrlInterpolation(): void
    {
        $result = $this->lint('<a href="{{ base }}/{{ path }}">');

        $this->assertSame([DiagnosticCode::AmbiguousUrlContext], $this->getDiagnosticCodes($result));
        $this->assertSame([
            [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute],
        ], $this->getPlans($result));
    }

    public function testRejectsUrlBranchesEndingInDifferentParts(): void
    {
        $result = $this->lint('<a href="{% if condition %}/path/{% else %}?query={% endif %}{{ value }}">');

        $this->assertSame([DiagnosticCode::AmbiguousControlFlow], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('URL path', $result->getDiagnostics()[0]->getMessage());
        $this->assertStringContainsString('URL query or fragment', $result->getDiagnostics()[0]->getMessage());
    }

    public function testTreatsCharacterReferencesBeforeTheUrlPartAsAmbiguous(): void
    {
        $result = $this->lint('<a href="&quest;query={{ value }}">');

        $this->assertSame([DiagnosticCode::AmbiguousUrlContext], $this->getDiagnosticCodes($result));
    }

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideMetaContentContexts
     */
    #[DataProvider('provideMetaContentContexts')]
    public function testInfersPlansForMetaContentContexts(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideMetaContentContexts(): iterable
    {
        yield 'named metadata' => [
            '<meta name="description" content="{{ value }}">',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'property metadata' => [
            '<meta property="og:title" content="{{ value }}">',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'content before its discriminator' => [
            '<meta content="{{ value }}" name="description">',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'content before another pragma' => [
            '<meta content="{{ value }}" http-equiv="content-type">',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'another pragma' => [
            '<meta http-equiv="content-security-policy" content="{{ value }}">',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'first duplicate http-equiv wins' => [
            '<meta http-equiv="content-type" http-equiv="refresh" content="{{ value }}">',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'duplicate content is plain' => [
            '<meta content="static" content="{{ value }}">',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'trusted ordinary metadata' => [
            '<meta name="description" content="{{ value|raw }}">',
            [[]],
        ];
        yield 'dynamic refresh delay' => [
            '<meta HTTP-EQUIV="Refresh" content="{{ value }}">',
            [[EscapeOperation::MetaRefreshDelay, EscapeOperation::HtmlAttribute]],
        ];
        yield 'dynamic delay suffix' => [
            '<meta http-equiv="refresh" content="1{{ value }}">',
            [[EscapeOperation::MetaRefreshDelay, EscapeOperation::HtmlAttribute]],
        ];
        yield 'dynamic delay and URL' => [
            '<meta http-equiv="refresh" content="{{ delay }};url={{ value }}">',
            [
                [EscapeOperation::MetaRefreshDelay, EscapeOperation::HtmlAttribute],
                [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute],
            ],
        ];
        yield 'constant discriminator expression' => [
            '<meta http-equiv="{{ "refresh" }}" content="{{ value }}">',
            [[EscapeOperation::MetaRefreshDelay, EscapeOperation::HtmlAttribute]],
        ];
        yield 'refresh URL' => [
            '<meta http-equiv="refresh" content="0; url={{ value }}">',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'refresh URL with whitespace around the equals sign' => [
            "<meta http-equiv=\"refresh\" content=\"0; URL \t= {{ value }}\">",
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'refresh URL path' => [
            '<meta http-equiv="refresh" content="0; url=/users/{{ value }}">',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
        yield 'refresh URL query' => [
            '<meta http-equiv="refresh" content="0; url=/search?q={{ value }}">',
            [[EscapeOperation::UrlQuery, EscapeOperation::HtmlAttribute]],
        ];
        yield 'single-quoted refresh URL' => [
            '<meta http-equiv="refresh" content="0; url=\'{{ value }}\'">',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'refresh URL after a whitespace separator' => [
            '<meta http-equiv="refresh" content="0 https://example.com/{{ value }}">',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
        yield 'unquoted refresh attribute' => [
            '<meta http-equiv=refresh content=0;url={{ value }}>',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttributeUnquoted]],
        ];
        yield 'explicit URL component' => [
            '<meta http-equiv="refresh" content="0;url={{ value|e("url") }}/suffix">',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'content after a quoted refresh URL' => [
            '<meta http-equiv="refresh" content="0;url=\'https://example.com\' ignored {{ value }}">',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'trusted refresh content' => [
            '<meta http-equiv="refresh" content="{{ value|raw }}">',
            [[EscapeOperation::HtmlAttribute]],
        ];
    }

    /**
     * @dataProvider provideAmbiguousMetaRefreshContexts
     */
    #[DataProvider('provideAmbiguousMetaRefreshContexts')]
    public function testRejectsAmbiguousMetaRefreshContexts(string $template, DiagnosticCode $code, array $expectedPlans = []): void
    {
        $result = $this->lint($template);

        $this->assertSame([$code], $this->getDiagnosticCodes($result));
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideAmbiguousMetaRefreshContexts(): iterable
    {
        yield 'dynamic discriminator' => [
            '<meta http-equiv="{{ kind }}" content="{{ value }}">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'refresh discriminator after content' => [
            '<meta content="{{ value }}" http-equiv="refresh">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'dynamic discriminator after content' => [
            '<meta content="{{ value }}" http-equiv="{{ kind }}">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
            [[EscapeOperation::HtmlAttribute], [EscapeOperation::HtmlAttribute]],
        ];
        yield 'conditional content before refresh discriminator' => [
            '<meta content="{% if condition %}{{ value }}{% endif %}" http-equiv="refresh">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'conditional discriminator' => [
            '<meta http-equiv="{% if condition %}refresh{% else %}content-type{% endif %}" content="{{ value }}">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
        ];
        yield 'dynamic output after delay whitespace' => [
            '<meta http-equiv="refresh" content="0 {{ value }}">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
        ];
        yield 'dynamic URL without a static prefix' => [
            '<meta http-equiv="refresh" content="0; {{ value }}">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
        ];
        yield 'dynamic URL after a comma' => [
            '<meta http-equiv="refresh" content="0,{{ value }}">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
        ];
        yield 'partial URL prefix' => [
            '<meta http-equiv="refresh" content="0; ur{{ value }}l=https://example.com">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
        ];
        yield 'character reference in the discriminator' => [
            '<meta http-equiv="&#114;efresh" content="{{ value }}">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
        ];
        yield 'character reference before the URL' => [
            '<meta http-equiv="refresh" content="0;&semi;url={{ value }}">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
        ];
        yield 'ambiguous URL part' => [
            '<meta http-equiv="refresh" content="0;url={{ base }}/{{ path }}">',
            DiagnosticCode::AmbiguousUrlContext,
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'output after trusted refresh content' => [
            '<meta http-equiv="refresh" content="{{ first|raw }}{{ second }}">',
            DiagnosticCode::AmbiguousMetaRefreshContext,
            [[EscapeOperation::HtmlAttribute]],
        ];
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

        $result = (new ContextualEscapingAnalyzer(new HtmlContextParser(new JavaScriptContextParser(), new CssContextParser(), new MetaRefreshContextParser(), new SrcsetContextParser())))->analyze($module);

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

    /**
     * @param array<string, string>       $templates
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideSupportedComposition
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
        yield 'include in URL path' => [
            [
                'index.html.twig' => '<a href="/users/{% include "partial.html.twig" %}">',
                'partial.html.twig' => '{{ value }}',
            ],
            'index.html.twig',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
        yield 'include in JavaScript' => [
            [
                'index.html.twig' => '<script>const value = {% include "partial.html.twig" %};</script>',
                'partial.html.twig' => '{{ value }}',
            ],
            'index.html.twig',
            [[EscapeOperation::JavaScriptValue]],
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

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::JavaScriptValue]], $this->getPlans($result));
    }

    public function testRejectsRecursiveComposition(): void
    {
        $result = $this->lintTemplates([
            'index.html.twig' => '{% include "index.html.twig" %}',
        ], 'index.html.twig');

        $this->assertSame([DiagnosticCode::UnsupportedTemplateComposition], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('Recursive composition', $result->getDiagnostics()[0]->getMessage());
    }

    /**
     * @dataProvider provideUnsupportedComposition
     */
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

    public function testAnalyzesSupportedSymfonyUxNodes(): void
    {
        require_once __DIR__.'/Fixtures/SymfonyUxNodes.php';

        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addTokenParser(new SymfonyUxNodeTokenParser('ux_props'));
        $environment->addTokenParser(new SymfonyUxNodeTokenParser('ux_component'));

        $result = $this->createLinter($environment)->lint(new Source('{% ux_props %}<div>{% ux_component %}</div>{{ value }}', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }

    public function testRejectsSymfonyUxComponentsOutsideHtmlText(): void
    {
        require_once __DIR__.'/Fixtures/SymfonyUxNodes.php';

        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addTokenParser(new SymfonyUxNodeTokenParser('ux_component'));

        $result = $this->createLinter($environment)->lint(new Source('<div title="{% ux_component %}">', 'index.html.twig'));

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('produces a complete HTML fragment', $result->getDiagnostics()[0]->getMessage());
    }

    public function testRejectsUnsupportedSymfonyUxNodeShapes(): void
    {
        require_once __DIR__.'/Fixtures/SymfonyUxNodes.php';

        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addTokenParser(new SymfonyUxNodeTokenParser('ux_invalid_component'));

        $result = $this->createLinter($environment)->lint(new Source('{% ux_invalid_component %}', 'index.html.twig'));

        $this->assertSame([DiagnosticCode::UnsupportedNode], $this->getDiagnosticCodes($result));
    }

    public function testRejectsAnUnknownStatementNodeEvenWithoutAnOutputMarker(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addTokenParser(new UnknownStatementTokenParser());

        $result = $this->createLinter($environment)->lint(new Source('{% unknown_statement %}{% unknown_statement %}', 'index.html.twig'));

        $this->assertSame([DiagnosticCode::UnsupportedNode, DiagnosticCode::UnsupportedNode], $this->getDiagnosticCodes($result));
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

        return $this->createLinter($environment)->lintTemplate($name);
    }

    private function createLinter(Environment $environment): ContextualEscapingLinter
    {
        return ContextualEscapingLinter::create($environment);
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

final class SymfonyUxNodeTokenParser extends AbstractTokenParser
{
    public function __construct(private string $tag)
    {
    }

    public function parse(Token $token): Node
    {
        $this->parser->getStream()->expect(Token::BLOCK_END_TYPE);

        return match ($this->tag) {
            'ux_props' => new \Symfony\UX\TwigComponent\Twig\PropsNode($token->getLine()),
            'ux_component' => new \Symfony\UX\TwigComponent\Twig\ComponentNode($token->getLine()),
            'ux_invalid_component' => new \Symfony\UX\TwigComponent\Twig\ComponentNode($token->getLine(), false),
        };
    }

    public function getTag(): string
    {
        return $this->tag;
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

#[YieldReady]
final class UnknownStatementNode extends Node
{
}
