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
final class JavaScriptContextParser
{
    private const REGEXP_PRECEDING_KEYWORDS = [
        'await',
        'case',
        'delete',
        'do',
        'else',
        'in',
        'instanceof',
        'new',
        'of',
        'return',
        'throw',
        'typeof',
        'void',
        'yield',
    ];

    public function consume(JavaScriptContext $context, string $character): JavaScriptContext
    {
        while (true) {
            switch ($context->getState()) {
                case JavaScriptState::Code:
                    if (JavaScriptTokenType::None !== $context->getTokenType()) {
                        if ($this->continuesToken($context->getTokenType(), $character)) {
                            return $context->withToken($context->getTokenType(), $context->getToken().$character);
                        }
                        $context = $this->finishToken($context);
                        continue 2;
                    }

                    return $this->consumeCodeCharacter($context, $character);
                case JavaScriptState::DoubleQuotedString:
                case JavaScriptState::SingleQuotedString:
                    if ($context->isEscaped()) {
                        return $context->withEscaped(false);
                    }
                    if ('\\' === $character) {
                        return $context->withEscaped(true);
                    }
                    if ((JavaScriptState::DoubleQuotedString === $context->getState() && '"' === $character) || (JavaScriptState::SingleQuotedString === $context->getState() && "'" === $character)) {
                        return $context->withState(JavaScriptState::Code, JavaScriptSlashContext::Division);
                    }
                    if ("\n" === $character || "\r" === $character) {
                        return $context->withState(JavaScriptState::Unknown, JavaScriptSlashContext::Unknown);
                    }

                    return $context;

                case JavaScriptState::TemplateString:
                    if ($context->isEscaped()) {
                        return $context->withEscaped(false);
                    }
                    if ('\\' === $character) {
                        return $context->withEscaped(true);
                    }
                    if ($context->hasTemplateDollar()) {
                        $context = $context->withTemplateDollar(false);
                        if ('{' === $character) {
                            return $context->enterTemplateExpression();
                        }
                        continue 2;
                    }
                    if ('$' === $character) {
                        return $context->withTemplateDollar(true);
                    }
                    if ('`' === $character) {
                        return $context->withState(JavaScriptState::Code, JavaScriptSlashContext::Division);
                    }

                    return $context;

                case JavaScriptState::Slash:
                    if ('/' === $character) {
                        return $context->withState(JavaScriptState::LineComment);
                    }
                    if ('*' === $character) {
                        return $context->withState(JavaScriptState::BlockComment);
                    }
                    if (JavaScriptSlashContext::RegExp === $context->getSlashContext()) {
                        $context = $context->withState(JavaScriptState::RegExp);
                        continue 2;
                    }
                    if (JavaScriptSlashContext::Division === $context->getSlashContext()) {
                        $context = $context->withState(JavaScriptState::Code, JavaScriptSlashContext::RegExp);
                        continue 2;
                    }

                    return $context->withState(JavaScriptState::Unknown, JavaScriptSlashContext::Unknown);

                case JavaScriptState::LessThan:
                    if ('!' === $character) {
                        return $context->withState(JavaScriptState::HtmlOpenCommentBang);
                    }
                    $context = $context->withState(JavaScriptState::Code, JavaScriptSlashContext::RegExp);
                    continue 2;
                case JavaScriptState::HtmlOpenCommentBang:
                    if ('-' === $character) {
                        return $context->withState(JavaScriptState::HtmlOpenCommentDash);
                    }
                    $context = $context->withState(JavaScriptState::Code, JavaScriptSlashContext::RegExp);
                    continue 2;
                case JavaScriptState::HtmlOpenCommentDash:
                    if ('-' === $character) {
                        return $context->withState(JavaScriptState::LineComment);
                    }
                    $context = $context->withState(JavaScriptState::Code, JavaScriptSlashContext::RegExp);
                    continue 2;
                case JavaScriptState::Minus:
                    if ('-' === $character) {
                        return $context->withState(JavaScriptState::HtmlCloseCommentDashDash);
                    }
                    $context = $context->withState(JavaScriptState::Code);
                    continue 2;
                case JavaScriptState::HtmlCloseCommentDashDash:
                    if ('>' === $character) {
                        return $context->withState(JavaScriptState::LineComment);
                    }
                    $context = $context->withState(JavaScriptState::Code);
                    continue 2;
                case JavaScriptState::RegExp:
                    if ($context->isEscaped()) {
                        return $context->withEscaped(false);
                    }
                    if ('\\' === $character) {
                        return $context->withEscaped(true);
                    }
                    if ('[' === $character && !$context->isRegExpCharacterClass()) {
                        return $context->withRegExpCharacterClass(true);
                    }
                    if (']' === $character && $context->isRegExpCharacterClass()) {
                        return $context->withRegExpCharacterClass(false);
                    }
                    if ('/' === $character && !$context->isRegExpCharacterClass()) {
                        return $context->withState(JavaScriptState::Code, JavaScriptSlashContext::Division);
                    }
                    if ("\n" === $character || "\r" === $character) {
                        return $context->withState(JavaScriptState::Unknown, JavaScriptSlashContext::Unknown);
                    }

                    return $context;

                case JavaScriptState::LineComment:
                    return "\n" === $character || "\r" === $character ? $context->withState(JavaScriptState::Code, JavaScriptSlashContext::RegExp) : $context;

                case JavaScriptState::BlockComment:
                    return '*' === $character ? $context->withState(JavaScriptState::BlockCommentStar) : $context;

                case JavaScriptState::BlockCommentStar:
                    if ('/' === $character) {
                        return $context->withState(JavaScriptState::Code, JavaScriptSlashContext::RegExp);
                    }

                    return '*' === $character ? $context : $context->withState(JavaScriptState::BlockComment);

                case JavaScriptState::Unknown:
                    return $context;
            }
        }
    }

    private function consumeCodeCharacter(JavaScriptContext $context, string $character): JavaScriptContext
    {
        if ($this->isWhitespace($character)) {
            return $context;
        }
        if ($this->startsIdentifier($character)) {
            return $context->withToken(JavaScriptTokenType::Identifier, $character);
        }
        if ($this->isDigit($character)) {
            return $context->withToken(JavaScriptTokenType::Number, $character);
        }

        switch ($character) {
            case '"':
                return $context->withState(JavaScriptState::DoubleQuotedString);
            case "'":
                return $context->withState(JavaScriptState::SingleQuotedString);
            case '`':
                return $context->withState(JavaScriptState::TemplateString);
            case '/':
                return $context->withState(JavaScriptState::Slash);
            case '<':
                return $context->withState(JavaScriptState::LessThan, JavaScriptSlashContext::RegExp);
            case '{':
                $context = $context->isInTemplateExpression() ? $context->increaseTemplateExpressionDepth() : $context;

                return $context->withSlashContext(JavaScriptSlashContext::RegExp);
            case '}':
                return $context->isInTemplateExpression() ? $context->closeTemplateExpressionBrace() : $context->withSlashContext(JavaScriptSlashContext::Unknown);
            case ')':
            case ']':
                return $context->withSlashContext(JavaScriptSlashContext::Division);
            case '+':
                return $context->withSlashContext(JavaScriptSlashContext::Division === $context->getSlashContext() ? JavaScriptSlashContext::Unknown : JavaScriptSlashContext::RegExp);
            case '-':
                return $context->withState(JavaScriptState::Minus, JavaScriptSlashContext::RegExp);
            case '(':
            case '[':
            case ',':
            case ';':
            case ':':
            case '?':
            case '=':
            case '!':
            case '~':
            case '*':
            case '%':
            case '&':
            case '|':
            case '^':
            case '>':
            case '.':
                return $context->withSlashContext(JavaScriptSlashContext::RegExp);
            default:
                return $context->withSlashContext(JavaScriptSlashContext::Unknown);
        }
    }

    private function finishToken(JavaScriptContext $context): JavaScriptContext
    {
        $slashContext = JavaScriptTokenType::Identifier === $context->getTokenType() && \in_array($context->getToken(), self::REGEXP_PRECEDING_KEYWORDS, true) ? JavaScriptSlashContext::RegExp : JavaScriptSlashContext::Division;

        return $context->withToken(JavaScriptTokenType::None, '')->withSlashContext($slashContext);
    }

    private function continuesToken(JavaScriptTokenType $type, string $character): bool
    {
        if (JavaScriptTokenType::Identifier === $type) {
            return $this->startsIdentifier($character) || $this->isDigit($character);
        }

        return $this->isDigit($character) || $this->startsIdentifier($character) || \in_array($character, ['.', '_'], true);
    }

    private function startsIdentifier(string $character): bool
    {
        return '_' === $character || '$' === $character || ('a' <= $character && 'z' >= $character) || ('A' <= $character && 'Z' >= $character) || 0x7F < \ord($character);
    }

    private function isDigit(string $character): bool
    {
        return '0' <= $character && '9' >= $character;
    }

    private function isWhitespace(string $character): bool
    {
        return \in_array($character, ["\t", "\n", "\v", "\f", "\r", ' '], true);
    }
}
