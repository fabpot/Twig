<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Audit;

/**
 * @internal
 *
 * @experimental
 */
final class ExpressionLocator
{
    public function findExpressionClosing(string $code, int $offset): ?int
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
