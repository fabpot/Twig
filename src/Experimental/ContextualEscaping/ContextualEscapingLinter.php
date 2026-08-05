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
use Twig\Source;

/**
 * @internal
 *
 * @experimental
 */
final class ContextualEscapingLinter
{
    public function __construct(
        private Environment $environment,
        private ContextualEscapingAnalyzer $analyzer,
    ) {
    }

    public function lint(Source $source, bool $force = false): AnalysisResult
    {
        if (!$force && !str_ends_with($source->getName(), '.html.twig')) {
            return new AnalysisResult(true);
        }

        return $this->analyzer->analyze($this->environment->parse($this->environment->tokenize($source)));
    }
}
