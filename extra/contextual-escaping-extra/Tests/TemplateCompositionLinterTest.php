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

class TemplateCompositionLinterTest extends AbstractLinterTestCase
{
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
        yield 'conditional inheritance' => [
            [
                'attribute.html.twig' => '<div title="{% block content %}{% endblock %}">',
                'javascript.html.twig' => '<script>const value = "{% block content %}{% endblock %}";</script>',
                'index.html.twig' => '{% extends use_javascript ? "javascript.html.twig" : "attribute.html.twig" %}{% block content %}{{ value }}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::JavaScriptString], [EscapeOperation::HtmlAttribute]],
        ];
        yield 'conditional inheritance ignores non-HTML output alternatives' => [
            [
                'attribute.html.twig' => '<div title="{% block content %}{% endblock %}">',
                'message.txt.twig' => '{% block content %}{% endblock %}',
                'index.html.twig' => '{% extends html ? "attribute.html.twig" : "message.txt.twig" %}{% block content %}{{ value }}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlAttribute]],
        ];
        yield 'conditional inheritance with only non-HTML output alternatives' => [
            [
                'plain.txt.twig' => '{% block content %}{% endblock %}',
                'data.json.twig' => '{% block content %}{% endblock %}',
                'index.html.twig' => '{% extends plain ? "plain.txt.twig" : "data.json.twig" %}{% block content %}{{ value }}{% endblock %}',
            ],
            'index.html.twig',
            [],
        ];
        yield 'conditional inheritance deduplicates equivalent analysis paths' => [
            [
                'paragraph.html.twig' => '<p>{% block content %}{% endblock %}</p>',
                'division.html.twig' => '<div>{% block content %}{% endblock %}</div>',
                'index.html.twig' => '{% extends use_paragraph ? "paragraph.html.twig" : "division.html.twig" %}{% block content %}{{ value }}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText]],
        ];
        yield 'conditional inheritance preserves maximum output multiplicity' => [
            [
                'once.html.twig' => '{% block content %}{% endblock %}',
                'twice.html.twig' => '{% block content %}{% endblock %}{{ block("content") }}',
                'index.html.twig' => '{% extends repeat ? "twice.html.twig" : "once.html.twig" %}{% block content %}{{ value }}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::HtmlText], [EscapeOperation::HtmlText]],
        ];
        yield 'conditional parent blocks' => [
            [
                'attribute.html.twig' => '<div title="{% block content %}{{ attribute_value }}{% endblock %}">',
                'javascript.html.twig' => '<script>const value = "{% block content %}{{ javascript_value }}{% endblock %}";</script>',
                'index.html.twig' => '{% extends use_javascript ? "javascript.html.twig" : "attribute.html.twig" %}{% block content %}{{ child_value }}{{ parent() }}{% endblock %}',
            ],
            'index.html.twig',
            [
                [EscapeOperation::JavaScriptString],
                [EscapeOperation::JavaScriptString],
                [EscapeOperation::HtmlAttribute],
                [EscapeOperation::HtmlAttribute],
            ],
        ];
        yield 'conditional inheritance with a self macro' => [
            [
                'attribute.html.twig' => '<div title="{% block content %}{% endblock %}">',
                'javascript.html.twig' => '<script>const value = "{% block content %}{% endblock %}";</script>',
                'index.html.twig' => '{% extends use_javascript ? "javascript.html.twig" : "attribute.html.twig" %}{% macro value() %}{{ value }}{% endmacro %}{% block content %}{{ _self.value() }}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::JavaScriptString], [EscapeOperation::HtmlAttribute]],
        ];
        yield 'nested conditional inheritance' => [
            [
                'attribute.html.twig' => '<div title="{% block content %}{% endblock %}">',
                'javascript.html.twig' => '<script>const value = "{% block content %}{% endblock %}";</script>',
                'middle.html.twig' => '{% extends use_javascript ? "javascript.html.twig" : "attribute.html.twig" %}',
                'index.html.twig' => '{% extends "middle.html.twig" %}{% block content %}{{ value }}{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::JavaScriptString], [EscapeOperation::HtmlAttribute]],
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
        yield 'block trait overridden with parent' => [
            [
                'index.html.twig' => '{% use "blocks.html.twig" %}{% block link %}{{ parent() }}{{ value }}">x</a>{% endblock %}',
                'blocks.html.twig' => '{% block link %}<a href="{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::HtmlAttribute]],
        ];
        yield 'nested block traits with parent calls' => [
            [
                'base.html.twig' => '{% block link %}<a href="{% endblock %}',
                'middle.html.twig' => '{% use "base.html.twig" %}{% block link %}{{ parent() }}middle/{% endblock %}',
                'index.html.twig' => '{% use "middle.html.twig" %}{% block link %}{{ parent() }}{{ value }}">x</a>{% endblock %}',
            ],
            'index.html.twig',
            [[EscapeOperation::UrlPath, EscapeOperation::HtmlAttribute]],
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

    public function testRejectsPartiallyDynamicConditionalInheritance(): void
    {
        $result = $this->lintTemplates([
            'base.html.twig' => '{% block content %}{% endblock %}',
            'index.html.twig' => '{% extends condition ? "base.html.twig" : parent_template %}{% block content %}{{ value }}{% endblock %}',
        ], 'index.html.twig');

        $this->assertSame([DiagnosticCode::UnsupportedTemplateComposition], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('Dynamic template references', $result->getDiagnostics()[0]->getMessage());
    }

    public function testRejectsRecursiveComposition(): void
    {
        $result = $this->lintTemplates([
            'index.html.twig' => '{% include "index.html.twig" %}',
        ], 'index.html.twig');

        $this->assertSame([DiagnosticCode::UnsupportedTemplateComposition], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('Recursive composition', $result->getDiagnostics()[0]->getMessage());
    }

    public function testRejectsReassignedMacroImports(): void
    {
        $result = $this->lintTemplates([
            'first.html.twig' => '{% macro value() %}<a href="{{ value }}">x</a>{% endmacro %}',
            'second.html.twig' => '{% macro value() %}{{ value }}{% endmacro %}',
            'index.html.twig' => '{% if condition %}{% import "first.html.twig" as macros %}{% else %}{% import "second.html.twig" as macros %}{% endif %}{{ macros.value() }}',
        ], 'index.html.twig');

        $this->assertSame([DiagnosticCode::UnsupportedTemplateComposition], $this->getDiagnosticCodes($result));
        $this->assertStringContainsString('Reassigning a macro import', $result->getDiagnostics()[0]->getMessage());
        $this->assertSame([], $result->getInferredEscapes());
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
}
