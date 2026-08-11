<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Context;

/**
 * @internal
 *
 * @experimental
 */
final class MetaRefreshContextParser
{
    public function consume(MetaRefreshContext $context, string $character): MetaRefreshContext
    {
        while (true) {
            switch ($context->getState()) {
                case MetaRefreshState::Delay:
                    if ($this->isWhitespace($character)) {
                        return $context->hasDelay() ? $context->withState(MetaRefreshState::DelayWhitespace) : $context;
                    }
                    if ('.' === $character || ('0' <= $character && '9' >= $character)) {
                        return $context->withDelay();
                    }
                    if (';' === $character || ',' === $character) {
                        return $context->withState(MetaRefreshState::BeforeUrl, UrlPart::Start);
                    }

                    return $context->withState(MetaRefreshState::Unknown);

                case MetaRefreshState::DelayWhitespace:
                    if ($this->isWhitespace($character)) {
                        return $context;
                    }
                    if (';' === $character || ',' === $character) {
                        return $context->withState(MetaRefreshState::BeforeUrl, UrlPart::Start);
                    }
                    $context = $context->withState(MetaRefreshState::Url, UrlPart::Start);
                    continue 2;
                case MetaRefreshState::BeforeUrl:
                    if ($this->isWhitespace($character)) {
                        return $context;
                    }
                    if ('"' === $character) {
                        return $context->withState(MetaRefreshState::UrlDoubleQuoted, UrlPart::Start);
                    }
                    if ("'" === $character) {
                        return $context->withState(MetaRefreshState::UrlSingleQuoted, UrlPart::Start);
                    }
                    if ('u' === strtolower($character)) {
                        return $context->withState(MetaRefreshState::UrlPrefix, UrlPart::Start)->withToken('u');
                    }
                    $context = $context->withState(MetaRefreshState::Url, UrlPart::Start);
                    continue 2;
                case MetaRefreshState::UrlPrefix:
                    $token = $context->getToken();
                    $expected = match ($token) {
                        'u' => 'r',
                        'ur' => 'l',
                        default => null,
                    };
                    if (null !== $expected && $expected === strtolower($character)) {
                        return $context->withToken($token.$expected);
                    }
                    if ('url' === $token) {
                        if ($this->isWhitespace($character)) {
                            return $context->withState(MetaRefreshState::UrlPrefixWhitespace, UrlPart::Start);
                        }
                        if ('=' === $character) {
                            return $context->withState(MetaRefreshState::UrlStart, UrlPart::Start);
                        }
                    }
                    $context = $context->withState(MetaRefreshState::Url, UrlPart::Path);
                    continue 2;
                case MetaRefreshState::UrlPrefixWhitespace:
                    if ($this->isWhitespace($character)) {
                        return $context;
                    }
                    if ('=' === $character) {
                        return $context->withState(MetaRefreshState::UrlStart, UrlPart::Start);
                    }
                    $context = $context->withState(MetaRefreshState::Url, UrlPart::Path);
                    continue 2;
                case MetaRefreshState::UrlStart:
                    if ($this->isWhitespace($character)) {
                        return $context;
                    }
                    if ('"' === $character) {
                        return $context->withState(MetaRefreshState::UrlDoubleQuoted, UrlPart::Start);
                    }
                    if ("'" === $character) {
                        return $context->withState(MetaRefreshState::UrlSingleQuoted, UrlPart::Start);
                    }
                    $context = $context->withState(MetaRefreshState::Url, UrlPart::Start);
                    continue 2;
                case MetaRefreshState::Url:
                    return $context->withUrlPart($context->getUrlPart()->consume($character));

                case MetaRefreshState::UrlDoubleQuoted:
                    if ('"' === $character) {
                        return $context->withState(MetaRefreshState::Done);
                    }

                    return $context->withUrlPart($context->getUrlPart()->consume($character));

                case MetaRefreshState::UrlSingleQuoted:
                    if ("'" === $character) {
                        return $context->withState(MetaRefreshState::Done);
                    }

                    return $context->withUrlPart($context->getUrlPart()->consume($character));

                case MetaRefreshState::Done:
                case MetaRefreshState::Unknown:
                    return $context;
            }
        }
    }

    private function isWhitespace(string $character): bool
    {
        return \in_array($character, ["\t", "\n", "\f", "\r", ' '], true);
    }
}
