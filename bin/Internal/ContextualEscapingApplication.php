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
use Twig\Node\PrintNode;

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
                $expression = $this->getExpressionSnippet($node);
                $siteKey = $templateName."\0".$node->getTemplateLine()."\0".($expression ?? '');
                $planKey = $siteKey."\0".implode("\0", $operations);
                $outputSites[$siteKey] = true;

                if ([] === $operations || [EscapeOperation::HtmlText->name] === $operations || isset($escapePlans[$planKey])) {
                    continue;
                }

                $escapePlans[$planKey] = true;
                printf(
                    "%s:%d [EscapePlan] %s%s\n",
                    $templateName,
                    $node->getTemplateLine(),
                    implode(' -> ', $operations),
                    null === $expression ? '' : ': '.$expression,
                );
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

    private function getExpressionSnippet(PrintNode $node): ?string
    {
        $source = $node->getSourceContext();
        if (null === $source) {
            return null;
        }

        $code = $source->getCode();
        $lineStart = 0;
        for ($line = 1; $line < $node->getTemplateLine(); ++$line) {
            if (false === $lineStart = strpos($code, "\n", $lineStart)) {
                return null;
            }
            ++$lineStart;
        }
        $lineEnd = strpos($code, "\n", $lineStart);
        if (false === $lineEnd) {
            $lineEnd = \strlen($code);
        }

        $openings = [];
        $offset = $lineStart;
        while (false !== $opening = strpos($code, '{{', $offset)) {
            if ($opening >= $lineEnd) {
                break;
            }
            $openings[] = $opening;
            $offset = $opening + 2;
        }

        if (1 !== \count($openings) || null === $closing = $this->findExpressionClosing($code, $openings[0] + 2)) {
            $line = trim(substr($code, $lineStart, $lineEnd - $lineStart));

            return '' === $line ? null : $line;
        }

        $expression = preg_replace('/\h*\R\h*/', ' ', trim(substr($code, $openings[0] + 2, $closing - $openings[0] - 2)));

        return '{{ '.$expression.' }}';
    }

    private function findExpressionClosing(string $code, int $offset): ?int
    {
        $delimiters = [];
        $quote = null;
        $escaped = false;
        $length = \strlen($code);

        for ($i = $offset; $i < $length; ++$i) {
            $character = $code[$i];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }

                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;

                continue;
            }
            if ('}' === $character && '}' === ($code[$i + 1] ?? null) && [] === $delimiters) {
                return $i;
            }
            if (isset(['(' => true, '[' => true, '{' => true][$character])) {
                $delimiters[] = $character;

                continue;
            }
            if (isset([')' => '(', ']' => '[', '}' => '{'][$character]) && end($delimiters) === [')' => '(', ']' => '[', '}' => '{'][$character]) {
                array_pop($delimiters);
            }
        }

        return null;
    }
}
