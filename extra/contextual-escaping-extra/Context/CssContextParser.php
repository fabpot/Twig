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
final class CssContextParser
{
    public function consume(CssContext $context, string $character): CssContext
    {
        while (true) {
            if (null !== $context->getEscapeDigits()) {
                if ($this->isHexDigit($character) && 6 > $context->getEscapeDigits()) {
                    return $context->withEscapeDigits(1 + $context->getEscapeDigits());
                }
                if (0 === $context->getEscapeDigits() || $this->isWhitespace($character)) {
                    return $context->withEscapeDigits(null);
                }
                $context = $context->withEscapeDigits(null);
                continue;
            }

            if ("\0" === $character || ('\\' === $character && \in_array($context->getState(), [CssState::Selector, CssState::Import, CssState::PropertyName, CssState::Value], true))) {
                return $context->withState(CssState::Unknown);
            }

            switch ($context->getState()) {
                case CssState::Selector:
                    if ('' !== $context->getToken()) {
                        if ($this->continuesIdentifier($character)) {
                            return $context->withToken($context->getToken().strtolower($character));
                        }
                        if ('@import' === $context->getToken()) {
                            $context = $context->withState(CssState::Import, CssState::Import);
                            continue 2;
                        }
                        if ('(' === $character && 'url' === $context->getToken()) {
                            return $context->enterUrl();
                        }
                        $context = $context->withToken('');
                        continue 2;
                    }
                    if ('@' === $character) {
                        return $context->withToken('@');
                    }
                    if ($this->startsIdentifier($character)) {
                        return $context->withToken(strtolower($character));
                    }
                    if ('/' === $character) {
                        return $context->withState(CssState::Slash, CssState::Selector);
                    }
                    if ('"' === $character) {
                        return $context->withState(CssState::DoubleQuotedString, CssState::Selector);
                    }
                    if ("'" === $character) {
                        return $context->withState(CssState::SingleQuotedString, CssState::Selector);
                    }
                    if ('{' === $character) {
                        return $context->withDeclarationDepth(1 + $context->getDeclarationDepth())->withState(CssState::PropertyName, CssState::PropertyName);
                    }

                    return $context;

                case CssState::Import:
                    if ('' !== $context->getToken()) {
                        if ($this->continuesIdentifier($character)) {
                            return $context->withToken($context->getToken().strtolower($character));
                        }
                        if ('(' === $character && 'url' === $context->getToken()) {
                            return $context->enterUrl();
                        }
                        $context = $context->withToken('');
                        continue 2;
                    }
                    if ($this->startsIdentifier($character)) {
                        return $context->withToken(strtolower($character));
                    }
                    if ($this->isWhitespace($character)) {
                        return $context;
                    }
                    if ('"' === $character) {
                        return $context->enterImportUrl(CssState::ImportUrlDoubleQuoted);
                    }
                    if ("'" === $character) {
                        return $context->enterImportUrl(CssState::ImportUrlSingleQuoted);
                    }
                    if ('/' === $character) {
                        return $context->withState(CssState::Slash, CssState::Import);
                    }
                    if (';' === $character) {
                        return $context->withState(CssState::Selector, CssState::Selector);
                    }

                    return $context;

                case CssState::PropertyName:
                    if ('' !== $context->getToken()) {
                        if ($this->continuesIdentifier($character)) {
                            return $context->withToken($context->getToken().strtolower($character));
                        }
                        $context = $context->withToken('');
                        continue 2;
                    }
                    if ($this->startsIdentifier($character)) {
                        return $context->withToken(strtolower($character));
                    }
                    if ('/' === $character) {
                        return $context->withState(CssState::Slash, CssState::PropertyName);
                    }
                    if ('"' === $character) {
                        return $context->withState(CssState::DoubleQuotedString, CssState::PropertyName);
                    }
                    if ("'" === $character) {
                        return $context->withState(CssState::SingleQuotedString, CssState::PropertyName);
                    }
                    if (':' === $character) {
                        return $context->withState(CssState::Value, CssState::Value);
                    }
                    if ('{' === $character) {
                        return $context->withDeclarationDepth(1 + $context->getDeclarationDepth());
                    }
                    if ('}' === $character) {
                        return $this->closeDeclaration($context);
                    }

                    return $context;

                case CssState::Value:
                    if ('' !== $context->getToken()) {
                        if ($this->continuesIdentifier($character)) {
                            return $context->withToken($context->getToken().strtolower($character));
                        }
                        if ('(' === $character && 'url' === $context->getToken()) {
                            return $context->enterUrl();
                        }
                        $context = $context->withToken('');
                        continue 2;
                    }
                    if ($this->startsIdentifier($character)) {
                        return $context->withToken(strtolower($character));
                    }
                    if ('/' === $character) {
                        return $context->withState(CssState::Slash, CssState::Value);
                    }
                    if ('"' === $character) {
                        return $context->withState(CssState::DoubleQuotedString, CssState::Value);
                    }
                    if ("'" === $character) {
                        return $context->withState(CssState::SingleQuotedString, CssState::Value);
                    }
                    if ('(' === $character) {
                        return $context->withParenthesisDepth(1 + $context->getParenthesisDepth());
                    }
                    if (')' === $character && 0 < $context->getParenthesisDepth()) {
                        return $context->withParenthesisDepth($context->getParenthesisDepth() - 1);
                    }
                    if (';' === $character && 0 === $context->getParenthesisDepth()) {
                        return $context->withState(CssState::PropertyName, CssState::PropertyName);
                    }
                    if ('}' === $character && 0 === $context->getParenthesisDepth()) {
                        return $this->closeDeclaration($context);
                    }

                    return $context;

                case CssState::DoubleQuotedString:
                case CssState::SingleQuotedString:
                    if ('\\' === $character) {
                        return $context->withEscapeDigits(0);
                    }
                    if ((CssState::DoubleQuotedString === $context->getState() && '"' === $character) || (CssState::SingleQuotedString === $context->getState() && "'" === $character)) {
                        return $context->withState($context->getReturnState(), $context->getReturnState());
                    }
                    if ("\n" === $character || "\r" === $character || "\f" === $character) {
                        return $context->withState(CssState::Unknown);
                    }

                    return $context;

                case CssState::Slash:
                    if ('*' === $character) {
                        return $context->withState(CssState::Comment, $context->getReturnState());
                    }
                    $context = $context->withState($context->getReturnState(), $context->getReturnState());
                    continue 2;
                case CssState::Comment:
                    return '*' === $character ? $context->withState(CssState::CommentStar, $context->getReturnState()) : $context;

                case CssState::CommentStar:
                    if ('/' === $character) {
                        return $context->withState($context->getReturnState(), $context->getReturnState());
                    }

                    return '*' === $character ? $context : $context->withState(CssState::Comment, $context->getReturnState());

                case CssState::UrlStart:
                    if ($this->isWhitespace($character)) {
                        return $context;
                    }
                    if ('"' === $character) {
                        return $context->withState(CssState::UrlDoubleQuoted, $context->getReturnState());
                    }
                    if ("'" === $character) {
                        return $context->withState(CssState::UrlSingleQuoted, $context->getReturnState());
                    }
                    if (')' === $character) {
                        return $context->leaveUrl();
                    }
                    $context = $context->withState(CssState::UrlUnquoted, $context->getReturnState());
                    continue 2;
                case CssState::UrlUnquoted:
                    if ($this->isWhitespace($character)) {
                        return $context->withState(CssState::UrlAfterValue, $context->getReturnState());
                    }
                    if (')' === $character) {
                        return $context->leaveUrl();
                    }
                    if ('"' === $character || "'" === $character || '(' === $character || "\n" === $character || "\r" === $character || "\f" === $character) {
                        return $context->withState(CssState::Unknown);
                    }
                    if ('\\' === $character) {
                        return $this->startUrlEscape($context);
                    }

                    return $context->withUrlPart($context->getUrlPart()->consume($character));

                case CssState::UrlDoubleQuoted:
                case CssState::UrlSingleQuoted:
                case CssState::ImportUrlDoubleQuoted:
                case CssState::ImportUrlSingleQuoted:
                    if ('\\' === $character) {
                        return $this->startUrlEscape($context);
                    }
                    if ((\in_array($context->getState(), [CssState::UrlDoubleQuoted, CssState::ImportUrlDoubleQuoted], true) && '"' === $character) || (\in_array($context->getState(), [CssState::UrlSingleQuoted, CssState::ImportUrlSingleQuoted], true) && "'" === $character)) {
                        return \in_array($context->getState(), [CssState::ImportUrlDoubleQuoted, CssState::ImportUrlSingleQuoted], true) ? $context->leaveUrl() : $context->withState(CssState::UrlAfterValue, $context->getReturnState());
                    }
                    if ("\n" === $character || "\r" === $character || "\f" === $character) {
                        return $context->withState(CssState::Unknown);
                    }

                    return $context->withUrlPart($context->getUrlPart()->consume($character));

                case CssState::UrlAfterValue:
                    if ($this->isWhitespace($character)) {
                        return $context;
                    }

                    return ')' === $character ? $context->leaveUrl() : $context->withState(CssState::Unknown);

                case CssState::Unknown:
                    return $context;
            }
        }
    }

    private function closeDeclaration(CssContext $context): CssContext
    {
        if ($context->isDeclarationList() && 1 >= $context->getDeclarationDepth()) {
            return $context->withState(CssState::PropertyName, CssState::PropertyName);
        }

        $depth = max(0, $context->getDeclarationDepth() - 1);
        $state = 0 === $depth ? CssState::Selector : CssState::PropertyName;

        return $context->withDeclarationDepth($depth)->withState($state, $state);
    }

    private function startUrlEscape(CssContext $context): CssContext
    {
        if (UrlPart::QueryOrFragment !== $context->getUrlPart()) {
            $context = $context->withUrlPart(UrlPart::Unknown);
        }

        return $context->withEscapeDigits(0);
    }

    private function startsIdentifier(string $character): bool
    {
        return '-' === $character || '_' === $character || ('a' <= $character && 'z' >= $character) || ('A' <= $character && 'Z' >= $character) || 0x7F < \ord($character);
    }

    private function continuesIdentifier(string $character): bool
    {
        return $this->startsIdentifier($character) || ('0' <= $character && '9' >= $character);
    }

    private function isHexDigit(string $character): bool
    {
        return ('0' <= $character && '9' >= $character) || ('a' <= $character && 'f' >= $character) || ('A' <= $character && 'F' >= $character);
    }

    private function isWhitespace(string $character): bool
    {
        return \in_array($character, ["\t", "\n", "\f", "\r", ' '], true);
    }
}
