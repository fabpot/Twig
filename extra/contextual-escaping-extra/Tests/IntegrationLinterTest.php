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
use Twig\Attribute\YieldReady;
use Twig\Environment;
use Twig\Extra\ContextualEscaping\Analysis\ContentType;
use Twig\Extra\ContextualEscaping\Analysis\DiagnosticCode;
use Twig\Extra\ContextualEscaping\Analysis\EscapeOperation;
use Twig\Extra\ContextualEscaping\Analysis\RuntimeOutputExpression;
use Twig\Loader\ArrayLoader;
use Twig\Node\Node;
use Twig\Source;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;
use Twig\TwigFunction;

class IntegrationLinterTest extends AbstractLinterTestCase
{
    public function testInfersSymfonyCallableContentTypes(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('component', ['Symfony\\UX\\TwigComponent\\Twig\\ComponentRuntime', 'render'], ['is_safe' => ['all']]));
        $environment->addFunction(new TwigFunction('asset', ['Symfony\\Bridge\\Twig\\Extension\\AssetExtension', 'getAssetUrl']));
        $environment->addFunction(new TwigFunction('path', ['Symfony\\Bridge\\Twig\\Extension\\RoutingExtension', 'getPath']));

        $result = $this->createLinter($environment)->lint(new Source('{{ component("Alert") }}<style>.x { background: url("{{ asset("x.svg") }}") }</style><a href="{{ path("home") }}">', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([
            [],
            [EscapeOperation::UrlNormalize, EscapeOperation::CssString],
            [EscapeOperation::HtmlAttribute],
        ], $this->getPlans($result));
        $contracts = $result->getInferredEscapes()[1]->getValueContracts();
        $this->assertCount(1, $contracts);
        $this->assertSame('asset()', $contracts[0]->getExpression());
        $this->assertSame('Symfony\\Bridge\\Twig\\Extension\\AssetExtension::getAssetUrl', $contracts[0]->getImplementation());
        $this->assertSame(ContentType::Url, $contracts[0]->getContentType());
        $this->assertSame('Symfony integration', $contracts[0]->getSource());
    }

    public function testDoesNotTrustSymfonyFunctionNamesWithOtherCallables(): void
    {
        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addFunction(new TwigFunction('component', static fn (): string => '<div></div>', ['is_safe' => ['all']]));
        $environment->addFunction(new TwigFunction('asset', static fn (): string => 'asset'));

        $result = $this->createLinter($environment)->lint(new Source('{{ component() }}<a href="{{ asset() }}">', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([
            [EscapeOperation::HtmlText],
            [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute],
        ], $this->getPlans($result));
    }

    public function testAnalyzesSymfonyFormAttributeMaps(): void
    {
        $template = <<<'TWIG'
<div {{ block('widget_attributes') }}>
{% block widget_attributes %}
    {% for attrname, attrvalue in attr %}
        {% if attrvalue is same as(true) %}
            {{ attrname }}="{{ attrname }}"
        {% elseif attrvalue is not same as(false) %}
            {{ attrname }}="{{ attrvalue }}"
        {% endif %}
    {% endfor %}
{% endblock %}
TWIG;

        $result = $this->lint($template);

        $this->assertSame([], $result->getDiagnostics());
    }

    public function testAnalyzesEasyAdminAttributeMaps(): void
    {
        $result = $this->lint('<link {% for attr, value in css_asset.htmlAttributes %} {{ attr }}="{{ value|e("html") }}"{% endfor %}>', '@EasyAdmin/includes/_css_assets.html.twig');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([], $this->getPlans($result));
    }

    public function testDoesNotTrustConventionalAttributeMapVariablesWithoutAProviderContract(): void
    {
        $result = $this->lint('<div {% for attrname, attrvalue in attr %} {{ attrname }}="{{ attrvalue }}"{% endfor %}>');

        $this->assertSame([DiagnosticCode::UnsupportedStructuralInterpolation], $this->getDiagnosticCodes($result));
    }

    /**
     * @dataProvider provideMalformedTrustedAttributeMapRenderers
     */
    #[DataProvider('provideMalformedTrustedAttributeMapRenderers')]
    public function testRejectsMalformedTrustedAttributeMapRenderers(string $template): void
    {
        $result = $this->lint($template, '@EasyAdmin/includes/_css_assets.html.twig');

        $this->assertSame([DiagnosticCode::UnsupportedStructuralInterpolation], $this->getDiagnosticCodes($result));
    }

    public static function provideMalformedTrustedAttributeMapRenderers(): iterable
    {
        yield 'unquoted attribute' => ['<link {% for attr, value in css_asset.htmlAttributes %} {{ attr }}={{ value|e("html") }}{% endfor %}>'];
        yield 'static delimiter output' => [<<<'TWIG'
            <link {% for attr, value in css_asset.htmlAttributes %} {{ attr }}="{{ condition ? '"' : value|e('html') }}"{% endfor %}>
            TWIG];
    }

    public function testAnalyzesSupportedSymfonyBridgeNodes(): void
    {
        require_once __DIR__.'/Fixtures/SymfonyBridgeNodes.php';

        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addTokenParser(new SymfonyBridgeNodeTokenParser('bridge_form_theme'));
        $environment->addTokenParser(new SymfonyBridgeNodeTokenParser('bridge_trans'));

        $result = $this->createLinter($environment)->lint(new Source('{% bridge_form_theme %}<p>{% bridge_trans %}</p><script>const value = "{% bridge_trans %}";</script>', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([
            [EscapeOperation::HtmlText],
            [EscapeOperation::JavaScriptString],
        ], $this->getPlans($result));
        foreach ($result->getInferredEscapes() as $inferredEscape) {
            $this->assertInstanceOf(RuntimeOutputExpression::class, $inferredEscape->getNode()->getNode('expr'));
        }
    }

    public function testRejectsUnsupportedSymfonyBridgeNodeShapes(): void
    {
        require_once __DIR__.'/Fixtures/SymfonyBridgeNodes.php';

        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addTokenParser(new SymfonyBridgeNodeTokenParser('bridge_invalid_form_theme'));
        $environment->addTokenParser(new SymfonyBridgeNodeTokenParser('bridge_invalid_trans'));

        $result = $this->createLinter($environment)->lint(new Source('{% bridge_invalid_form_theme %}{% bridge_invalid_trans %}', 'index.html.twig'));

        $this->assertSame([DiagnosticCode::UnsupportedNode, DiagnosticCode::UnsupportedNode], $this->getDiagnosticCodes($result));
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

    public function testAnalyzesSymfonyUxComponentAttributes(): void
    {
        require_once __DIR__.'/Fixtures/SymfonyUxNodes.php';

        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addTokenParser(new SymfonyUxNodeTokenParser('ux_props'));

        $result = $this->createLinter($environment)->lint(new Source('{% ux_props %}<div {{ attributes.defaults({class: "button"}).without("id") }} title="{{ attributes.render("title") }}">', 'index.html.twig'));

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[], []], $this->getPlans($result));
    }

    public function testRejectsSymfonyUxComponentAttributesOutsideAttributeListContext(): void
    {
        require_once __DIR__.'/Fixtures/SymfonyUxNodes.php';

        $environment = new Environment(new ArrayLoader(), ['optimizations' => 0]);
        $environment->addTokenParser(new SymfonyUxNodeTokenParser('ux_props'));

        $result = $this->createLinter($environment)->lint(new Source('{% ux_props %}<p>{{ attributes }}</p>', 'index.html.twig'));

        $this->assertSame([DiagnosticCode::UnsupportedOutputContext], $this->getDiagnosticCodes($result));
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
}

final class SymfonyBridgeNodeTokenParser extends AbstractTokenParser
{
    public function __construct(private string $tag)
    {
    }

    public function parse(Token $token): Node
    {
        $this->parser->getStream()->expect(Token::BLOCK_END_TYPE);

        return match ($this->tag) {
            'bridge_form_theme' => new \Symfony\Bridge\Twig\Node\FormThemeNode($token->getLine()),
            'bridge_trans' => new \Symfony\Bridge\Twig\Node\TransNode($token->getLine()),
            'bridge_invalid_form_theme' => new \Symfony\Bridge\Twig\Node\FormThemeNode($token->getLine(), false),
            'bridge_invalid_trans' => new \Symfony\Bridge\Twig\Node\TransNode($token->getLine(), false),
        };
    }

    public function getTag(): string
    {
        return $this->tag;
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
