<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Runtime\EscaperRuntime;

/**
 * Checks the strategy coverage EscaperRuntime relies on against the escapers themselves.
 *
 * Content escaped with a strategy is safe in another context when no character able to
 * escape that context survives the escaping.
 */
class EscaperStrategyCoverageTest extends TestCase
{
    /**
     * Characters that let content escape a context when they are not escaped.
     */
    private const CONTEXT_BREAKING_CHARACTERS = [
        'html' => ['<'],
        'html_attr' => ['"', "'", '`', '=', '<', '>', ' ', "\t", "\n", "\r", "\f"],
        'html_attr_relaxed' => ['"', "'", '`', '=', '<', '>', ' ', "\t", "\n", "\r", "\f"],
        'js' => ['"', "'", '`', '\\', "\n", "\r", "\u{2028}", "\u{2029}", '<'],
        'css' => ['"', "'", '\\', "\n", '{', '}', ';', ':', '(', ')', '<', '@', '/'],
        'url' => [':', '"', "'", '<', '>', ' ', '&'],
    ];

    /**
     * @dataProvider provideStrategyPairs
     */
    #[DataProvider('provideStrategyPairs')]
    public function testCoverageMatchesTheEscapers(bool $covered, string $strategy, string $context): void
    {
        $payload = implode('', array_map('chr', range(1, 127)))."\u{2028}\u{2029}é€";

        $escaped = (new EscaperRuntime('UTF-8'))->escape($payload, $strategy);
        $breaking = array_filter(self::CONTEXT_BREAKING_CHARACTERS[$context], static fn ($character) => str_contains($escaped, $character));

        if ($covered) {
            $this->assertSame([], array_values($breaking), \sprintf('The "%s" strategy is declared to cover the "%s" context.', $strategy, $context));
        } else {
            $this->assertNotSame([], array_values($breaking), \sprintf('The "%s" strategy is not declared to cover the "%s" context, but it escapes everything that context needs.', $strategy, $context));
        }
    }

    /**
     * Mirrors EscaperRuntime::STRATEGY_COVERAGE: every pair is checked, in both directions.
     */
    public static function provideStrategyPairs()
    {
        $coverage = [
            'html' => [],
            'html_attr' => ['html', 'html_attr_relaxed', 'js'],
            'html_attr_relaxed' => ['html', 'html_attr', 'js'],
            'js' => ['html', 'html_attr', 'html_attr_relaxed', 'url'],
            'css' => ['html'],
            'url' => ['html', 'html_attr', 'html_attr_relaxed', 'js', 'css'],
        ];

        foreach ($coverage as $strategy => $contexts) {
            foreach (array_keys($coverage) as $context) {
                if ($strategy === $context) {
                    continue;
                }

                yield \sprintf('%s covers %s: %s', $strategy, $context, \in_array($context, $contexts, true) ? 'yes' : 'no') => [\in_array($context, $contexts, true), $strategy, $context];
            }
        }
    }
}
