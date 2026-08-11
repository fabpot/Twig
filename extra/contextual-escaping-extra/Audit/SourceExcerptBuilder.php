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
final class SourceExcerptBuilder
{
    /**
     * @return array{expression: string|null, uri: string|null, lines: list<array{number: int, before: string, highlight: string|null, after: string}>}|null
     */
    public function create(?string $path, int $line, ?string $expression): ?array
    {
        if (null === $path || !is_file($path) || false === $code = file_get_contents($path)) {
            return null === $expression ? null : ['expression' => $expression, 'uri' => null, 'lines' => []];
        }

        $code = str_replace(["\r\n", "\r"], "\n", $code);
        $lines = explode("\n", $code);
        $target = max(0, min(\count($lines) - 1, $line - 1));
        $starts = [];
        $offset = 0;
        foreach ($lines as $sourceLine) {
            $starts[] = $offset;
            $offset += \strlen($sourceLine) + 1;
        }

        $range = $this->findHighlightRange($code, $lines, $starts, $target, $expression);
        $rangeEndLine = $target;
        foreach ($starts as $index => $start) {
            if ($start >= $range[1]) {
                break;
            }
            $rangeEndLine = $index;
        }
        $first = max(0, $target - 2);
        $last = min(\count($lines) - 1, max($target + 2, $rangeEndLine + 2));
        $sourceLines = [];
        for ($index = $first; $index <= $last; ++$index) {
            $sourceLine = $lines[$index];
            $start = $starts[$index];
            $end = $start + \strlen($sourceLine);
            $highlightStart = max($start, $range[0]);
            $highlightEnd = min($end, $range[1]);
            $before = $sourceLine;
            $highlight = null;
            $after = '';
            if ($highlightStart < $highlightEnd) {
                $before = substr($sourceLine, 0, $highlightStart - $start);
                $highlight = substr($sourceLine, $highlightStart - $start, $highlightEnd - $highlightStart);
                $after = substr($sourceLine, $highlightEnd - $start);
            }
            $sourceLines[] = [
                'number' => 1 + $index,
                'before' => $before,
                'highlight' => $highlight,
                'after' => $after,
            ];
        }

        return ['expression' => null, 'uri' => $this->createFileUri($path), 'lines' => $sourceLines];
    }

    public function createFileUri(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return 'file://'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * @param list<string> $lines
     * @param list<int>    $starts
     *
     * @return array{int, int}
     */
    private function findHighlightRange(string $code, array $lines, array $starts, int $target, ?string $expression): array
    {
        $lineStart = $starts[$target];
        $lineEnd = $lineStart + \strlen($lines[$target]);
        if (null !== $expression && str_starts_with(ltrim($expression), '<twig:')) {
            if (false !== $opening = stripos($code, '<twig:', $lineStart)) {
                if ($opening <= $lineEnd && null !== $closing = $this->findTagClosing($code, $opening + 6)) {
                    return [$opening, $closing + 1];
                }
            }
        }
        if (null !== $expression && str_starts_with($expression, '{{')) {
            if (false !== $exact = strpos($code, $expression, $lineStart)) {
                if ($exact <= $lineEnd) {
                    return [$exact, $exact + \strlen($expression)];
                }
            }
            if (false !== $opening = strpos($code, '{{', $lineStart)) {
                if ($opening <= $lineEnd && null !== $closing = $this->findExpressionClosing($code, $opening + 2)) {
                    return [$opening, $closing + 2];
                }
            }
        }
        if (null !== $expression && false !== $exact = strpos($lines[$target], $expression)) {
            return [$lineStart + $exact, $lineStart + $exact + \strlen($expression)];
        }

        return [$lineStart, $lineEnd];
    }

    private function findTagClosing(string $code, int $offset): ?int
    {
        $quote = null;
        $length = \strlen($code);
        for ($i = $offset; $i < $length; ++$i) {
            $character = $code[$i];
            if (null !== $quote) {
                if ($quote === $character && '\\' !== ($code[$i - 1] ?? null)) {
                    $quote = null;
                }

                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;

                continue;
            }
            if ('>' === $character) {
                return $i;
            }
        }

        return null;
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
