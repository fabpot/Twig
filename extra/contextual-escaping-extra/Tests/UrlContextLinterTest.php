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

class UrlContextLinterTest extends AbstractLinterTestCase
{
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

    /**
     * @dataProvider provideExecutableSchemeContexts
     */
    #[DataProvider('provideExecutableSchemeContexts')]
    public function testRejectsOutputAfterAStaticExecutableScheme(string $template): void
    {
        $result = $this->lint($template);

        $this->assertSame([DiagnosticCode::UnsafeUrlScheme], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('executable code', $result->getDiagnostics()[0]->getMessage());
    }

    public static function provideExecutableSchemeContexts(): iterable
    {
        yield 'javascript scheme' => ['<a href="javascript:{{ value }}">'];
        yield 'uppercase javascript scheme' => ['<a href="JAVASCRIPT:{{ value }}">'];
        yield 'javascript scheme with stripped tab' => ["<a href=\"java\tscript:{{ value }}\">"];
        yield 'vbscript scheme' => ['<a href="vbscript:{{ value }}">'];
        yield 'unquoted javascript scheme' => ['<a href=javascript:{{ value }}>'];
        yield 'iframe javascript scheme' => ['<iframe src="javascript:{{ value }}"></iframe>'];
        yield 'query delimiter after an executable scheme' => ['<a href="javascript:void(0)?next={{ value }}">'];
        yield 'trusted value after an executable scheme' => ['<a href="javascript:{{ value|raw }}">'];
        yield 'static executable scheme expression' => ['<a href="{{ "javascript:" }}{{ value }}">'];
        yield 'identical executable scheme branches' => ['<a href="{% if condition %}javascript:{% else %}javascript:{% endif %}{{ value }}">'];
    }

    public function testRejectsBranchesMixingExecutableAndSafeSchemes(): void
    {
        $result = $this->lint('<a href="{% if condition %}javascript:{% else %}/path/{% endif %}{{ value }}">');

        $this->assertSame([DiagnosticCode::AmbiguousControlFlow], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('executable-scheme URL', $result->getDiagnostics()[0]->getMessage());
        $this->assertStringContainsString('URL path', $result->getDiagnostics()[0]->getMessage());
    }

    /**
     * @param list<list<EscapeOperation>> $expectedPlans
     *
     * @dataProvider provideNonExecutableSchemeContexts
     */
    #[DataProvider('provideNonExecutableSchemeContexts')]
    public function testInfersPlansAfterNonExecutableSchemes(string $template, array $expectedPlans): void
    {
        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame($expectedPlans, $this->getPlans($result));
    }

    public static function provideNonExecutableSchemeContexts(): iterable
    {
        yield 'https scheme' => [
            '<a href="https://example.com/{{ value }}">',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
        yield 'mailto scheme' => [
            '<a href="mailto:{{ value }}">',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
        yield 'javascript path segment without a scheme delimiter' => [
            '<a href="/javascript/{{ value }}">',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
        ];
        yield 'javascript query value' => [
            '<a href="/run?lang=javascript:{{ value }}">',
            [[EscapeOperation::UrlQuery, EscapeOperation::HtmlAttribute]],
        ];
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
}
