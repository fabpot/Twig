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
        $outputSites = [];
        $escapePlans = [];
        $diagnostics = [];
        $unsupportedNodes = [];

        foreach (ContextualEscapingLinter::create($this->twig)->lintDirectory($this->templateDirectory) as $name => $result) {
            ++$templateCount;

            foreach ($result->getInferredEscapes() as $inferredEscape) {
                $node = $inferredEscape->getNode();
                $templateName = $node->getTemplateName() ?? $name;
                $operations = array_map(static fn (EscapeOperation $operation): string => $operation->name, $inferredEscape->getPlan()->getOperations());
                $siteKey = $templateName."\0".$node->getTemplateLine();
                $planKey = $siteKey."\0".implode("\0", $operations);
                $outputSites[$siteKey] = true;

                if ([] === $operations || [EscapeOperation::HtmlText->name] === $operations || isset($escapePlans[$planKey])) {
                    continue;
                }

                $escapePlans[$planKey] = true;
                printf("%s:%d [EscapePlan] %s\n", $templateName, $node->getTemplateLine(), implode(' -> ', $operations));
            }

            foreach ($result->getDiagnostics() as $diagnostic) {
                $templateName = $diagnostic->getTemplateName() ?? $name;
                $key = $templateName."\0".$diagnostic->getTemplateLine()."\0".$diagnostic->getCode()->name."\0".$diagnostic->getMessage();
                if (isset($diagnostics[$key])) {
                    continue;
                }
                $diagnostics[$key] = true;

                if (DiagnosticCode::UnsupportedNode === $diagnostic->getCode()) {
                    $unsupportedNodes[$diagnostic->getMessage()] = 1 + ($unsupportedNodes[$diagnostic->getMessage()] ?? 0);

                    continue;
                }

                fprintf(
                    \STDERR,
                    "%s:%d [%s] %s\n",
                    $templateName,
                    $diagnostic->getTemplateLine(),
                    $diagnostic->getCode()->name,
                    $diagnostic->getMessage(),
                );
            }
        }

        ksort($unsupportedNodes);
        foreach ($unsupportedNodes as $message => $count) {
            fprintf(\STDERR, "[UnsupportedNode] %d occurrence%s: %s\n", $count, 1 === $count ? '' : 's', $message);
        }

        printf(
            "Analyzed %d template%s and %d output site%s; found %d contextual escape plan%s and %d diagnostic%s.\n",
            $templateCount,
            1 === $templateCount ? '' : 's',
            \count($outputSites),
            1 === \count($outputSites) ? '' : 's',
            \count($escapePlans),
            1 === \count($escapePlans) ? '' : 's',
            \count($diagnostics),
            1 === \count($diagnostics) ? '' : 's',
        );

        return $diagnostics ? 1 : 0;
    }
}
