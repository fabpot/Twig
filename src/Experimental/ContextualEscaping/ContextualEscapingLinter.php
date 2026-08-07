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
