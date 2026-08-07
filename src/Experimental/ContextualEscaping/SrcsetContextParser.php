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

/**
 * @internal
 *
 * @experimental
 */
final class SrcsetContextParser
{
    public function consume(SrcsetContext $context, string $character): SrcsetContext
    {
        while (true) {
            switch ($context->getState()) {
                case SrcsetState::BeforeUrl:
                    if ($this->isWhitespace($character) || ',' === $character) {
                        return $context;
                    }

                    $context = $context->withState(SrcsetState::Url, UrlPart::Start);
                    continue 2;
                case SrcsetState::Url:
                    if ($this->isWhitespace($character)) {
                        return $context->withState(SrcsetState::BeforeDescriptor);
                    }
                    if (',' === $character) {
                        return $context->withState(SrcsetState::UrlComma, $context->getUrlPart());
                    }

                    return $this->consumeUrlCharacter($context, $character);
                case SrcsetState::UrlComma:
                    if (',' === $character) {
                        return $context;
                    }
                    if ($this->isWhitespace($character)) {
                        return $context->withState(SrcsetState::BeforeUrl);
                    }

                    $context = $context->withState(SrcsetState::Url, $context->getUrlPart());
                    continue 2;
                case SrcsetState::BeforeDescriptor:
                    if ($this->isWhitespace($character)) {
                        return $context;
                    }
                    if (',' === $character) {
                        return $context->withState(SrcsetState::BeforeUrl);
                    }
                    if ('(' === $character) {
                        return $context->withState(SrcsetState::DescriptorParenthesized);
                    }

                    return $context->withState(SrcsetState::Descriptor);
                case SrcsetState::Descriptor:
                    if ($this->isWhitespace($character)) {
                        return $context->withState(SrcsetState::AfterDescriptor);
                    }
                    if (',' === $character) {
                        return $context->withState(SrcsetState::BeforeUrl);
                    }
                    if ('(' === $character) {
                        return $context->withState(SrcsetState::DescriptorParenthesized);
                    }

                    return $context;
                case SrcsetState::DescriptorParenthesized:
                    return ')' === $character ? $context->withState(SrcsetState::Descriptor) : $context;

                case SrcsetState::AfterDescriptor:
                    if ($this->isWhitespace($character)) {
                        return $context;
                    }
                    if (',' === $character) {
                        return $context->withState(SrcsetState::BeforeUrl);
                    }
                    if ('(' === $character) {
                        return $context->withState(SrcsetState::DescriptorParenthesized);
                    }

                    return $context->withState(SrcsetState::Descriptor);
                case SrcsetState::Unknown:
                    return $context;
            }
        }
    }

    private function consumeUrlCharacter(SrcsetContext $context, string $character): SrcsetContext
    {
        if ('?' === $character || '#' === $character) {
            return $context->withUrlPart(UrlPart::QueryOrFragment);
        }
        if ('&' === $character && UrlPart::QueryOrFragment !== $context->getUrlPart()) {
            return $context->withUrlPart(UrlPart::Unknown);
        }
        if (UrlPart::Start === $context->getUrlPart()) {
            return $context->withUrlPart(UrlPart::Path);
        }

        return $context;
    }

    private function isWhitespace(string $character): bool
    {
        return \in_array($character, ["\t", "\n", "\f", "\r", ' '], true);
    }
}
