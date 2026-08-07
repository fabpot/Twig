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

final class ContextualEscapingApplication
{
    public function __construct(
        private Environment $twig,
        private string $templateDirectory,
    ) {
    }

    public function run(): int
    {
        $templateCount = 0;
        $diagnosticCount = 0;
        foreach (ContextualEscapingLinter::create($this->twig)->lintDirectory($this->templateDirectory) as $name => $result) {
            ++$templateCount;
            foreach ($result->getDiagnostics() as $diagnostic) {
                ++$diagnosticCount;
                fprintf(
                    \STDERR,
                    "%s:%d [%s] %s\n",
                    $diagnostic->getTemplateName() ?? $name,
                    $diagnostic->getTemplateLine(),
                    $diagnostic->getCode()->name,
                    $diagnostic->getMessage(),
                );
            }
        }

        printf(
            "Linted %d template%s; found %d diagnostic%s.\n",
            $templateCount,
            1 === $templateCount ? '' : 's',
            $diagnosticCount,
            1 === $diagnosticCount ? '' : 's',
        );

        return $diagnosticCount ? 1 : 0;
    }
}
