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
final class HtmlContextParser
{
    private const URL_ATTRIBUTES = [
        'action',
        'background',
        'cite',
        'classid',
        'code',
        'codebase',
        'data',
        'formaction',
        'href',
        'icon',
        'itemid',
        'longdesc',
        'manifest',
        'poster',
        'profile',
        'src',
        'usemap',
        'xmlns',
    ];

    public function __construct(
        private JavaScriptContextParser $javaScriptContextParser,
    ) {
    }

    public function consume(HtmlContext $context, string $text): HtmlContext
    {
        $length = \strlen($text);
        $offset = 0;

        while ($offset < $length) {
            if (HtmlState::Dead === $context->getState()) {
                return $context;
            }

            $character = $text[$offset];
            $consume = true;
            $javaScriptContext = $context->getState()->isScriptData() ? $context->getJavaScriptContext() : null;

            switch ($context->getState()) {
                case HtmlState::Text:
                    if ('<' === $character) {
                        $context = $context->withState(HtmlState::TagOpen);
                    }
                    break;

                case HtmlState::TagOpen:
                    if ('!' === $character) {
                        $context = $context->startCandidate(HtmlState::MarkupDeclarationOpen);
                    } elseif ('/' === $character) {
                        $context = $context->withState(HtmlState::EndTagOpen);
                    } elseif ($this->isAsciiAlpha($character)) {
                        $context = $context->startTag(false, $character);
                    } elseif ('?' === $character) {
                        $context = $context->withState(HtmlState::Declaration);
                    } else {
                        $context = $context->toText();
                        $consume = false;
                    }
                    break;

                case HtmlState::EndTagOpen:
                    if ($this->isAsciiAlpha($character)) {
                        $context = $context->startTag(true, $character);
                    } elseif ('>' === $character) {
                        $context = $context->toText();
                    } else {
                        $context = $context->withState(HtmlState::Declaration);
                        $consume = false;
                    }
                    break;

                case HtmlState::TagName:
                    if ($this->isHtmlSpace($character)) {
                        $context = $context->withState(HtmlState::BeforeAttributeName);
                    } elseif ('/' === $character) {
                        $context = $context->withState(HtmlState::SelfClosingStartTag);
                    } elseif ('>' === $character) {
                        $context = $this->completeTag($context);
                    } else {
                        $context = $context->appendTagName($character);
                    }
                    break;

                case HtmlState::BeforeAttributeName:
                    if ($this->isHtmlSpace($character)) {
                        break;
                    }
                    if ('/' === $character) {
                        $context = $context->withState(HtmlState::SelfClosingStartTag);
                    } elseif ('>' === $character) {
                        $context = $this->completeTag($context);
                    } else {
                        $context = $context->startAttribute($character);
                    }
                    break;

                case HtmlState::AttributeName:
                    if ($this->isHtmlSpace($character)) {
                        $context = $this->finishAttributeName($context, HtmlState::AfterAttributeName);
                    } elseif ('/' === $character) {
                        $context = $this->finishAttributeName($context, HtmlState::SelfClosingStartTag);
                    } elseif ('=' === $character) {
                        $context = $this->finishAttributeName($context, HtmlState::BeforeAttributeValue);
                    } elseif ('>' === $character) {
                        $context = $this->completeTag($this->finishAttributeName($context, HtmlState::BeforeAttributeName));
                    } else {
                        $context = $context->appendAttributeName($character);
                    }
                    break;

                case HtmlState::AfterAttributeName:
                    if ($this->isHtmlSpace($character)) {
                        break;
                    }
                    if ('/' === $character) {
                        $context = $context->withState(HtmlState::SelfClosingStartTag);
                    } elseif ('=' === $character) {
                        $context = $context->withState(HtmlState::BeforeAttributeValue);
                    } elseif ('>' === $character) {
                        $context = $this->completeTag($context);
                    } else {
                        $context = $context->startAttribute($character);
                    }
                    break;

                case HtmlState::BeforeAttributeValue:
                    if ($this->isHtmlSpace($character)) {
                        break;
                    }
                    if ('"' === $character) {
                        $context = $context->withState(HtmlState::AttributeValueDoubleQuoted);
                    } elseif ("'" === $character) {
                        $context = $context->withState(HtmlState::AttributeValueSingleQuoted);
                    } elseif ('>' === $character) {
                        $context = $this->completeTag($context);
                    } else {
                        $context = $context->withState(HtmlState::AttributeValueUnquoted);
                        $consume = false;
                    }
                    break;

                case HtmlState::AttributeValueDoubleQuoted:
                    if ('"' === $character) {
                        $context = $context->clearAttribute(HtmlState::BeforeAttributeName);
                    } else {
                        $context = $this->consumeAttributeCharacter($context, $character);
                    }
                    break;

                case HtmlState::AttributeValueSingleQuoted:
                    if ("'" === $character) {
                        $context = $context->clearAttribute(HtmlState::BeforeAttributeName);
                    } else {
                        $context = $this->consumeAttributeCharacter($context, $character);
                    }
                    break;

                case HtmlState::AttributeValueUnquoted:
                    if ($this->isHtmlSpace($character)) {
                        $context = $context->clearAttribute(HtmlState::BeforeAttributeName);
                    } elseif ('>' === $character) {
                        $context = $this->completeTag($context);
                    } else {
                        $context = $this->consumeAttributeCharacter($context, $character);
                    }
                    break;

                case HtmlState::SelfClosingStartTag:
                    if ('>' === $character) {
                        $context = $this->completeTag($context);
                    } else {
                        $context = $context->withState(HtmlState::BeforeAttributeName);
                        $consume = false;
                    }
                    break;

                case HtmlState::MarkupDeclarationOpen:
                    $candidate = $context->getCandidate().$character;
                    if (str_starts_with('--', $candidate)) {
                        $context = $context->appendCandidate($character);
                        if ('--' === $candidate) {
                            $context = $context->startCandidate(HtmlState::CommentStart);
                        }
                    } else {
                        $context = $context->withState(HtmlState::Declaration);
                        $consume = false;
                    }
                    break;

                case HtmlState::Declaration:
                    if ('"' === $character) {
                        $context = $context->withState(HtmlState::DeclarationDoubleQuoted);
                    } elseif ("'" === $character) {
                        $context = $context->withState(HtmlState::DeclarationSingleQuoted);
                    } elseif ('>' === $character) {
                        $context = $context->toText();
                    }
                    break;

                case HtmlState::DeclarationDoubleQuoted:
                    if ('"' === $character) {
                        $context = $context->withState(HtmlState::Declaration);
                    }
                    break;

                case HtmlState::DeclarationSingleQuoted:
                    if ("'" === $character) {
                        $context = $context->withState(HtmlState::Declaration);
                    }
                    break;

                case HtmlState::CommentStart:
                    if ('-' === $character) {
                        $context = $context->withState(HtmlState::CommentStartDash);
                    } elseif ('>' === $character) {
                        $context = $context->toText();
                    } else {
                        $context = $context->withState(HtmlState::Comment);
                        $consume = false;
                    }
                    break;

                case HtmlState::CommentStartDash:
                    if ('-' === $character) {
                        $context = $context->withState(HtmlState::CommentEnd);
                    } elseif ('>' === $character) {
                        $context = $context->toText();
                    } else {
                        $context = $context->withState(HtmlState::Comment);
                        $consume = false;
                    }
                    break;

                case HtmlState::Comment:
                    if ('-' === $character) {
                        $context = $context->withState(HtmlState::CommentEndDash);
                    }
                    break;

                case HtmlState::CommentEndDash:
                    if ('-' === $character) {
                        $context = $context->withState(HtmlState::CommentEnd);
                    } else {
                        $context = $context->withState(HtmlState::Comment);
                        $consume = false;
                    }
                    break;

                case HtmlState::CommentEnd:
                    if ('>' === $character) {
                        $context = $context->toText();
                    } elseif ('!' === $character) {
                        $context = $context->withState(HtmlState::CommentEndBang);
                    } elseif ('-' !== $character) {
                        $context = $context->withState(HtmlState::Comment);
                        $consume = false;
                    }
                    break;

                case HtmlState::CommentEndBang:
                    if ('>' === $character) {
                        $context = $context->toText();
                    } elseif ('-' === $character) {
                        $context = $context->withState(HtmlState::CommentEndDash);
                    } else {
                        $context = $context->withState(HtmlState::Comment);
                        $consume = false;
                    }
                    break;

                case HtmlState::Rcdata:
                    if ('<' === $character) {
                        $context = $context->withState(HtmlState::RcdataLessThanSign);
                    }
                    break;

                case HtmlState::RcdataLessThanSign:
                    if ('/' === $character) {
                        $context = $context->startCandidate(HtmlState::RcdataEndTagOpen);
                    } else {
                        $context = $context->resumeSpecial(HtmlState::Rcdata);
                        $consume = false;
                    }
                    break;

                case HtmlState::RcdataEndTagOpen:
                    if ($this->isAsciiAlpha($character)) {
                        $context = $context->startCandidate(HtmlState::RcdataEndTagName, $character);
                    } else {
                        $context = $context->resumeSpecial(HtmlState::Rcdata);
                        $consume = false;
                    }
                    break;

                case HtmlState::RcdataEndTagName:
                    [$context, $consume] = $this->consumeSpecialEndTag($context, $character, HtmlState::Rcdata);
                    break;

                case HtmlState::RawText:
                    if ('<' === $character) {
                        $context = $context->withState(HtmlState::RawTextLessThanSign);
                    }
                    break;

                case HtmlState::RawTextLessThanSign:
                    if ('/' === $character) {
                        $context = $context->startCandidate(HtmlState::RawTextEndTagOpen);
                    } else {
                        $context = $context->resumeSpecial(HtmlState::RawText);
                        $consume = false;
                    }
                    break;

                case HtmlState::RawTextEndTagOpen:
                    if ($this->isAsciiAlpha($character)) {
                        $context = $context->startCandidate(HtmlState::RawTextEndTagName, $character);
                    } else {
                        $context = $context->resumeSpecial(HtmlState::RawText);
                        $consume = false;
                    }
                    break;

                case HtmlState::RawTextEndTagName:
                    [$context, $consume] = $this->consumeSpecialEndTag($context, $character, HtmlState::RawText);
                    break;

                case HtmlState::Plaintext:
                    break;

                case HtmlState::ScriptData:
                    if ('<' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataLessThanSign);
                    }
                    break;

                case HtmlState::ScriptDataLessThanSign:
                    if ('/' === $character) {
                        $context = $context->startCandidate(HtmlState::ScriptDataEndTagOpen);
                    } elseif ('!' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataEscapeStart);
                    } else {
                        $context = $context->resumeSpecial(HtmlState::ScriptData);
                        $consume = false;
                    }
                    break;

                case HtmlState::ScriptDataEndTagOpen:
                    if ($this->isAsciiAlpha($character)) {
                        $context = $context->startCandidate(HtmlState::ScriptDataEndTagName, $character);
                    } else {
                        $context = $context->resumeSpecial(HtmlState::ScriptData);
                        $consume = false;
                    }
                    break;

                case HtmlState::ScriptDataEndTagName:
                    [$context, $consume] = $this->consumeSpecialEndTag($context, $character, HtmlState::ScriptData);
                    break;

                case HtmlState::ScriptDataEscapeStart:
                    if ('-' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataEscapeStartDash);
                    } else {
                        $context = $context->resumeSpecial(HtmlState::ScriptData);
                        $consume = false;
                    }
                    break;

                case HtmlState::ScriptDataEscapeStartDash:
                    if ('-' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataEscaped);
                    } else {
                        $context = $context->resumeSpecial(HtmlState::ScriptData);
                        $consume = false;
                    }
                    break;

                case HtmlState::ScriptDataEscaped:
                    if ('-' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataEscapedDash);
                    } elseif ('<' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataEscapedLessThanSign);
                    }
                    break;

                case HtmlState::ScriptDataEscapedDash:
                    if ('-' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataEscapedDashDash);
                    } elseif ('<' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataEscapedLessThanSign);
                    } else {
                        $context = $context->withState(HtmlState::ScriptDataEscaped);
                    }
                    break;

                case HtmlState::ScriptDataEscapedDashDash:
                    if ('<' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataEscapedLessThanSign);
                    } elseif ('>' === $character) {
                        $context = $context->withState(HtmlState::ScriptData);
                    } elseif ('-' !== $character) {
                        $context = $context->withState(HtmlState::ScriptDataEscaped);
                    }
                    break;

                case HtmlState::ScriptDataEscapedLessThanSign:
                    if ('/' === $character) {
                        $context = $context->startCandidate(HtmlState::ScriptDataEscapedEndTagOpen);
                    } elseif ($this->isAsciiAlpha($character)) {
                        $context = $context->startCandidate(HtmlState::ScriptDataDoubleEscapeStart, $character);
                    } else {
                        $context = $context->withState(HtmlState::ScriptDataEscaped);
                        $consume = false;
                    }
                    break;

                case HtmlState::ScriptDataEscapedEndTagOpen:
                    if ($this->isAsciiAlpha($character)) {
                        $context = $context->startCandidate(HtmlState::ScriptDataEscapedEndTagName, $character);
                    } else {
                        $context = $context->withState(HtmlState::ScriptDataEscaped);
                        $consume = false;
                    }
                    break;

                case HtmlState::ScriptDataEscapedEndTagName:
                    [$context, $consume] = $this->consumeSpecialEndTag($context, $character, HtmlState::ScriptDataEscaped);
                    break;

                case HtmlState::ScriptDataDoubleEscapeStart:
                    if ($this->isAsciiAlpha($character)) {
                        $context = $context->appendCandidate($character);
                    } elseif ($this->isScriptDoubleEscapeDelimiter($character)) {
                        $context = $context->resumeSpecial('script' === $context->getCandidate() ? HtmlState::ScriptDataDoubleEscaped : HtmlState::ScriptDataEscaped);
                    } else {
                        $context = $context->resumeSpecial(HtmlState::ScriptDataEscaped);
                        $consume = false;
                    }
                    break;

                case HtmlState::ScriptDataDoubleEscaped:
                    if ('-' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataDoubleEscapedDash);
                    } elseif ('<' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataDoubleEscapedLessThanSign);
                    }
                    break;

                case HtmlState::ScriptDataDoubleEscapedDash:
                    if ('-' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataDoubleEscapedDashDash);
                    } elseif ('<' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataDoubleEscapedLessThanSign);
                    } else {
                        $context = $context->withState(HtmlState::ScriptDataDoubleEscaped);
                    }
                    break;

                case HtmlState::ScriptDataDoubleEscapedDashDash:
                    if ('<' === $character) {
                        $context = $context->withState(HtmlState::ScriptDataDoubleEscapedLessThanSign);
                    } elseif ('>' === $character) {
                        $context = $context->withState(HtmlState::ScriptData);
                    } elseif ('-' !== $character) {
                        $context = $context->withState(HtmlState::ScriptDataDoubleEscaped);
                    }
                    break;

                case HtmlState::ScriptDataDoubleEscapedLessThanSign:
                    if ('/' === $character) {
                        $context = $context->startCandidate(HtmlState::ScriptDataDoubleEscapeEnd);
                    } else {
                        $context = $context->withState(HtmlState::ScriptDataDoubleEscaped);
                        $consume = false;
                    }
                    break;

                case HtmlState::ScriptDataDoubleEscapeEnd:
                    if ($this->isAsciiAlpha($character)) {
                        $context = $context->appendCandidate($character);
                    } elseif ($this->isScriptDoubleEscapeDelimiter($character)) {
                        $context = $context->resumeSpecial('script' === $context->getCandidate() ? HtmlState::ScriptDataEscaped : HtmlState::ScriptDataDoubleEscaped);
                    } else {
                        $context = $context->resumeSpecial(HtmlState::ScriptDataDoubleEscaped);
                        $consume = false;
                    }
                    break;

                case HtmlState::Dead:
                    return $context;
            }

            if ($consume) {
                if (null !== $javaScriptContext && null !== $context->getJavaScriptContext()) {
                    $context = $context->withJavaScriptContext($this->javaScriptContextParser->consume($javaScriptContext, $character));
                }
                ++$offset;
            }
        }

        return $context;
    }

    private function consumeAttributeCharacter(HtmlContext $context, string $character): HtmlContext
    {
        $context = $context->consumeUrlCharacter($character);
        if (null !== $javaScriptContext = $context->getJavaScriptContext()) {
            $javaScriptContext = '&' === $character ? $javaScriptContext->withState(JavaScriptState::Unknown, JavaScriptSlashContext::Unknown) : $this->javaScriptContextParser->consume($javaScriptContext, $character);
            $context = $context->withJavaScriptContext($javaScriptContext);
        }

        return $context;
    }

    private function completeTag(HtmlContext $context): HtmlContext
    {
        if ($context->isClosingTag()) {
            return $context->toText();
        }

        return match ($context->getTagName()) {
            'title', 'textarea' => $context->enterElement(HtmlState::Rcdata, $context->getTagName()),
            'script' => $context->enterElement(HtmlState::ScriptData, $context->getTagName()),
            'iframe', 'noembed', 'noframes', 'noscript', 'style', 'xmp' => $context->enterElement(HtmlState::RawText, $context->getTagName()),
            'plaintext' => $context->enterElement(HtmlState::Plaintext, $context->getTagName()),
            default => $context->toText(),
        };
    }

    private function finishAttributeName(HtmlContext $context, HtmlState $state): HtmlContext
    {
        return $context->finishAttributeName($this->classifyAttribute($context->getTagName(), $context->getAttributeName()), $state);
    }

    private function classifyAttribute(string $tagName, string $name): HtmlAttributeType
    {
        if ('meta' === $tagName && 'content' === $name) {
            return HtmlAttributeType::MetaContent;
        }
        if (str_starts_with($name, 'data-')) {
            $name = substr($name, 5);
        } elseif (str_contains($name, ':')) {
            [$prefix, $name] = explode(':', $name, 2);
            if ('xmlns' === $prefix) {
                return HtmlAttributeType::Url;
            }
        }

        if (\in_array($name, ['archive', 'ping'], true)) {
            return HtmlAttributeType::UrlList;
        }
        if (str_starts_with($name, 'on')) {
            return HtmlAttributeType::JavaScript;
        }
        if ('style' === $name) {
            return HtmlAttributeType::Style;
        }
        if (\in_array($name, ['imagesrcset', 'srcset'], true)) {
            return HtmlAttributeType::Srcset;
        }
        if ('srcdoc' === $name) {
            return HtmlAttributeType::Html;
        }
        if (\in_array($name, self::URL_ATTRIBUTES, true) || str_contains($name, 'src') || str_contains($name, 'uri') || str_contains($name, 'url')) {
            return HtmlAttributeType::Url;
        }

        return HtmlAttributeType::Plain;
    }

    /**
     * @return array{HtmlContext, bool}
     */
    private function consumeSpecialEndTag(HtmlContext $context, string $character, HtmlState $fallbackState): array
    {
        if ($this->isAsciiAlpha($character)) {
            return [$context->appendCandidate($character), true];
        }

        if ($this->isTagNameDelimiter($character) && $context->getCandidate() === $context->getElement()) {
            return [$context->startTag(true, $context->getCandidate()), false];
        }

        return [$context->resumeSpecial($fallbackState), false];
    }

    private function isTagNameDelimiter(string $character): bool
    {
        return '>' === $character || '/' === $character || $this->isHtmlSpace($character);
    }

    private function isScriptDoubleEscapeDelimiter(string $character): bool
    {
        return '/' === $character || '>' === $character || $this->isHtmlSpace($character);
    }

    private function isHtmlSpace(string $character): bool
    {
        return \in_array($character, ["\t", "\n", "\f", "\r", ' '], true);
    }

    private function isAsciiAlpha(string $character): bool
    {
        return ('a' <= $character && 'z' >= $character) || ('A' <= $character && 'Z' >= $character);
    }
}
