<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Experimental\ContextualEscaping;

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Source;

/**
 * @experimental
 */
final class ContextualEscapingLinter
{
    private function __construct(
        private Environment $environment,
        private ContextualEscapingAnalyzer $analyzer,
    ) {
    }

    public static function create(Environment $environment): self
    {
        return new self(
            $environment,
            new ContextualEscapingAnalyzer(
                new HtmlContextParser(
                    new JavaScriptContextParser(),
                    new CssContextParser(),
                    new MetaRefreshContextParser(),
                    new SrcsetContextParser(),
                ),
                new EnvironmentTemplateResolver($environment),
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

        return $this->analyzer->analyze($this->environment->parse($this->environment->tokenize($source)));
    }
}
