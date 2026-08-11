<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping;

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Extra\ContextualEscaping\Analysis\AnalysisResult;
use Twig\Extra\ContextualEscaping\Analysis\Analyzer;
use Twig\Extra\ContextualEscaping\Analysis\AttributeMapAnalyzerRegistry;
use Twig\Extra\ContextualEscaping\Analysis\CallableAnalyzerRegistry;
use Twig\Extra\ContextualEscaping\Analysis\CurrentEscapingSafetyAnalyzer;
use Twig\Extra\ContextualEscaping\Analysis\Diagnostic;
use Twig\Extra\ContextualEscaping\Analysis\DiagnosticCode;
use Twig\Extra\ContextualEscaping\Analysis\EnvironmentTemplateResolver;
use Twig\Extra\ContextualEscaping\Analysis\HtmlAttributeMapLoopShapeAnalyzer;
use Twig\Extra\ContextualEscaping\Analysis\NodeAnalyzerRegistry;
use Twig\Extra\ContextualEscaping\Analysis\StaticExpressionAnalyzer;
use Twig\Extra\ContextualEscaping\Context\CssContextParser;
use Twig\Extra\ContextualEscaping\Context\HtmlContextParser;
use Twig\Extra\ContextualEscaping\Context\JavaScriptContextParser;
use Twig\Extra\ContextualEscaping\Context\MetaRefreshContextParser;
use Twig\Extra\ContextualEscaping\Context\SrcsetContextParser;
use Twig\Extra\ContextualEscaping\Integration\EasyAdminAttributeMapAnalyzer;
use Twig\Extra\ContextualEscaping\Integration\SymfonyBridgeNodeAnalyzer;
use Twig\Extra\ContextualEscaping\Integration\SymfonyCallableAnalyzer;
use Twig\Extra\ContextualEscaping\Integration\SymfonyFormAttributeMapAnalyzer;
use Twig\Extra\ContextualEscaping\Integration\SymfonyUxNodeAnalyzer;
use Twig\Source;

/**
 * @experimental
 */
final class Linter
{
    private function __construct(
        private Environment $environment,
        private Analyzer $analyzer,
    ) {
    }

    public static function create(Environment $environment, ?CurrentEscapingSafetyAnalyzer $currentSafetyAnalyzer = null, ?NodeAnalyzerRegistry $nodeAnalyzerRegistry = null, ?CallableAnalyzerRegistry $callableAnalyzerRegistry = null, ?AttributeMapAnalyzerRegistry $attributeMapAnalyzerRegistry = null): self
    {
        $currentSafetyAnalyzer ??= new CurrentEscapingSafetyAnalyzer($environment);
        $nodeAnalyzerRegistry ??= new NodeAnalyzerRegistry([new SymfonyUxNodeAnalyzer(), new SymfonyBridgeNodeAnalyzer()]);
        $callableAnalyzerRegistry ??= new CallableAnalyzerRegistry([new SymfonyCallableAnalyzer()]);
        if (null === $attributeMapAnalyzerRegistry) {
            $shapeAnalyzer = new HtmlAttributeMapLoopShapeAnalyzer();
            $attributeMapAnalyzerRegistry = new AttributeMapAnalyzerRegistry([new SymfonyFormAttributeMapAnalyzer($shapeAnalyzer), new EasyAdminAttributeMapAnalyzer($shapeAnalyzer)]);
        }

        return new self(
            $environment,
            new Analyzer(
                new HtmlContextParser(
                    new JavaScriptContextParser(),
                    new CssContextParser(),
                    new MetaRefreshContextParser(),
                    new SrcsetContextParser(),
                ),
                new EnvironmentTemplateResolver($environment),
                $currentSafetyAnalyzer,
                $nodeAnalyzerRegistry,
                new StaticExpressionAnalyzer($environment),
                $callableAnalyzerRegistry,
                $attributeMapAnalyzerRegistry,
            ),
        );
    }

    /**
     * @throws LoaderError When the template cannot be found
     * @throws SyntaxError When the template is syntactically invalid
     */
    public function lintTemplate(string $name, bool $force = false): AnalysisResult
    {
        return $this->lint($this->environment->getLoader()->getSourceContext($name), $force);
    }

    /**
     * @return \Generator<string, AnalysisResult>
     *
     * @throws \InvalidArgumentException When the path is not a directory
     * @throws \RuntimeException         When a template cannot be read
     */
    public function lintDirectory(string $directory, ?string $namespace = null, string $extension = '.html.twig'): \Generator
    {
        $requestedDirectory = $directory;
        if (false === $directory = realpath($directory)) {
            throw new \InvalidArgumentException(\sprintf('The "%s" directory does not exist.', $requestedDirectory));
        }
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException(\sprintf('The "%s" path is not a directory.', $requestedDirectory));
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $extension)) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths, \SORT_STRING);

        $prefix = rtrim($directory, '/\\').\DIRECTORY_SEPARATOR;
        foreach ($paths as $path) {
            $relativeName = str_replace(\DIRECTORY_SEPARATOR, '/', substr($path, \strlen($prefix)));
            $name = null === $namespace ? $relativeName : '@'.$namespace.'/'.$relativeName;
            if (false === $contents = file_get_contents($path)) {
                throw new \RuntimeException(\sprintf('Unable to read the "%s" template.', $path));
            }

            try {
                $result = $this->lint(new Source($contents, $name, $path), true);
            } catch (SyntaxError $error) {
                $source = $error->getSourceContext();
                $result = new AnalysisResult();
                $result->addDiagnostic(new Diagnostic(
                    DiagnosticCode::SyntaxError,
                    $error->getRawMessage(),
                    $error->getTemplateLine(),
                    $source?->getName() ?? $name,
                ));
            }

            yield $name => $result;
        }
    }

    /**
     * @throws SyntaxError When the template is syntactically invalid
     */
    public function lint(Source $source, bool $force = false): AnalysisResult
    {
        if (!$force && !str_ends_with($source->getName(), '.html.twig')) {
            return new AnalysisResult(true);
        }

        $previousHandler = null;
        $previousHandler = set_error_handler(function (int $type, string $message, string $file, int $line) use ($source, &$previousHandler): bool {
            if (\E_USER_DEPRECATED === $type && $this->isTemplateDeprecation($message)) {
                throw $this->createDeprecationError($message, $source);
            }

            return null === $previousHandler ? false : (bool) $previousHandler($type, $message, $file, $line);
        });

        try {
            return $this->analyzer->analyze($this->environment->parse($this->environment->tokenize($source)));
        } finally {
            restore_error_handler();
        }
    }

    private function isTemplateDeprecation(string $message): bool
    {
        return str_contains($message, ' at line ') && !str_contains($message, 'is not marked as ready for using "yield"');
    }

    private function createDeprecationError(string $message, Source $source): SyntaxError
    {
        $line = 1;
        if (preg_match('/(?:^|[ (])in (?:"([^"]+)"|(\S+)) at line (\d+)\)?\.?$/', $message, $matches)) {
            $name = $matches[1] ?: $matches[2];
            $line = (int) $matches[3];
            if ($name !== $source->getName()) {
                $source = new Source('', $name);
            }
        }

        return new SyntaxError('Contextual escaping analysis only supports templates without deprecations: '.$message, $line, $source);
    }
}
