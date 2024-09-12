<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\String;

use Symfony\Component\String\AbstractUnicodeString;
use Symfony\Component\String\Inflector\EnglishInflector;
use Symfony\Component\String\Inflector\FrenchInflector;
use Symfony\Component\String\Inflector\InflectorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\String\UnicodeString;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class StringExtension extends AbstractExtension
{
    private $frenchInflector;
    private $englishInflector;

    public function __construct(
        private ?SluggerInterface $slugger = null
    ) {
        if (!interface_exists(SluggerInterface::class)) {
            throw new \LogicException('You cannot use the "String" extension as the "symfony/string" package is not installed. Try running "composer require symfony/string".');
        }
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('u', [$this, 'createUnicodeString']),
            new TwigFilter('slug', [$this, 'createSlug']),
            new TwigFilter('plural', [$this, 'plural']),
            new TwigFilter('singular', [$this, 'singular']),
        ];
    }

    public function createUnicodeString(?string $text): UnicodeString
    {
        return new UnicodeString($text ?? '');
    }

    public function createSlug(string $string, string $separator = '-', ?string $locale = null): AbstractUnicodeString
    {
        if (!$this->slugger) {
            $this->slugger = new AsciiSlugger();
        }

        return $this->slugger->slug($string, $separator, $locale);
    }

    /**
     * @return array|string
     */
    public function plural(string $value, string $locale = 'en', bool $all = false)
    {
        if ($all) {
            return $this->getInflector($locale)->pluralize($value);
        }

        return $this->getInflector($locale)->pluralize($value)[0];
    }

    /**
     * @return array|string
     */
    public function singular(string $value, string $locale = 'en', bool $all = false)
    {
        if ($all) {
            return $this->getInflector($locale)->singularize($value);
        }

        return $this->getInflector($locale)->singularize($value)[0];
    }

    private function getInflector(string $locale): InflectorInterface
    {
        switch ($locale) {
            case 'fr':
                return $this->frenchInflector ?? $this->frenchInflector = new FrenchInflector();
            case 'en':
                return $this->englishInflector ?? $this->englishInflector = new EnglishInflector();
            default:
                throw new \InvalidArgumentException(\sprintf('Locale "%s" is not supported.', $locale));
        }
    }
}
