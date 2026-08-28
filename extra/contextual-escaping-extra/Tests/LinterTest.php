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

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Extension\ProfilerExtension;
use Twig\Extra\ContextualEscaping\Analysis\DiagnosticCode;
use Twig\Extra\ContextualEscaping\Analysis\EscapeOperation;
use Twig\Extra\ContextualEscaping\Linter;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Node\Node;
use Twig\Profiler\Profile;
use Twig\Source;
use Twig\TwigFunction;

class LinterTest extends AbstractLinterTestCase
{
    public function testSkipsUnsupportedTemplatesByDefault(): void
    {
        foreach (['index.txt.twig', 'index.json.twig'] as $name) {
            $result = $this->lint('{{ value }}', $name);

            $this->assertTrue($result->isSkipped());
            $this->assertSame([], $result->getDiagnostics());
            $this->assertSame([], $result->getInferredEscapes());
        }
    }

    public function testLintsStandaloneJavaScriptAndCssTemplatesByDefault(): void
    {
        $environment = new Environment(new ArrayLoader([
            'index.js.twig' => 'const value = "{{ value }}";',
            'index.css.twig' => '.notice { content: "{{ value }}"; }',
        ]), ['optimizations' => 0]);
        $linter = Linter::create($environment);

        $this->assertSame([[EscapeOperation::JavaScriptString]], $this->getPlans($linter->lintTemplate('index.js.twig')));
        $this->assertSame([[EscapeOperation::CssString]], $this->getPlans($linter->lintTemplate('index.css.twig')));
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

        $result = Linter::create($environment)->lintTemplate('index.html.twig');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }

    public function testIgnoresProfilerInstrumentation(): void
    {
        $environment = new Environment(new ArrayLoader(['index.html.twig' => '<p>{{ value }}</p>']), ['optimizations' => 0]);
        $environment->addExtension(new ProfilerExtension(new Profile()));

        $result = Linter::create($environment)->lintTemplate('index.html.twig');

        $this->assertSame([], $result->getDiagnostics());
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($result));
    }

    public function testCanLintADirectory(): void
    {
        $directory = __DIR__.'/Fixtures/directory';
        $environment = new Environment(new FilesystemLoader($directory), ['optimizations' => 0]);

        $results = iterator_to_array(Linter::create($environment)->lintDirectory($directory));

        $this->assertSame([
            'deprecated.html.twig',
            'first.html.twig',
            'ignored.css.twig',
            'ignored.js.twig',
            'nested/second.html.twig',
            'syntax-error.html.twig',
        ], array_keys($results));
        $this->assertSame([[EscapeOperation::HtmlText]], $this->getPlans($results['first.html.twig']));
        $this->assertSame([[EscapeOperation::CssValue]], $this->getPlans($results['ignored.css.twig']));
        $this->assertSame([[EscapeOperation::JavaScriptValue]], $this->getPlans($results['ignored.js.twig']));
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

        $results = iterator_to_array(Linter::create($environment)->lintDirectory($directory, 'scripts', '.js.twig'));

        $this->assertSame(['@scripts/ignored.js.twig'], array_keys($results));
        $this->assertSame([[EscapeOperation::JavaScriptValue]], $this->getPlans($results['@scripts/ignored.js.twig']));
        $inferredEscape = $results['@scripts/ignored.js.twig']->getInferredEscapes()[0];
        $this->assertSame('@scripts/ignored.js.twig', $inferredEscape->getNode()->getTemplateName());
        $this->assertSame('JavaScript Code', $inferredEscape->getContext());
    }

    public function testCanLintADirectoryWithCustomExtensions(): void
    {
        $directory = __DIR__.'/Fixtures/directory';
        $environment = new Environment(new FilesystemLoader($directory), ['optimizations' => 0]);

        $results = iterator_to_array(Linter::create($environment)->lintDirectory($directory, extension: ['.js.twig', '.css.twig']));

        $this->assertSame(['ignored.css.twig', 'ignored.js.twig'], array_keys($results));
    }

    public function testRejectsANonexistentLintDirectory(): void
    {
        $linter = Linter::create(new Environment(new ArrayLoader()));

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
}
