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
enum HtmlState
{
    case JavaScriptDocument;
    case CssDocument;
    case Text;
    case TagOpen;
    case EndTagOpen;
    case TagName;
    case BeforeAttributeName;
    case AttributeName;
    case AfterAttributeName;
    case BeforeAttributeValue;
    case AttributeValueDoubleQuoted;
    case AttributeValueSingleQuoted;
    case AttributeValueUnquoted;
    case SelfClosingStartTag;
    case MarkupDeclarationOpen;
    case Declaration;
    case DeclarationDoubleQuoted;
    case DeclarationSingleQuoted;
    case CommentStart;
    case CommentStartDash;
    case Comment;
    case CommentEndDash;
    case CommentEnd;
    case CommentEndBang;
    case Rcdata;
    case RcdataLessThanSign;
    case RcdataEndTagOpen;
    case RcdataEndTagName;
    case RawText;
    case RawTextLessThanSign;
    case RawTextEndTagOpen;
    case RawTextEndTagName;
    case Plaintext;
    case ScriptData;
    case ScriptDataLessThanSign;
    case ScriptDataEndTagOpen;
    case ScriptDataEndTagName;
    case ScriptDataEscapeStart;
    case ScriptDataEscapeStartDash;
    case ScriptDataEscaped;
    case ScriptDataEscapedDash;
    case ScriptDataEscapedDashDash;
    case ScriptDataEscapedLessThanSign;
    case ScriptDataEscapedEndTagOpen;
    case ScriptDataEscapedEndTagName;
    case ScriptDataDoubleEscapeStart;
    case ScriptDataDoubleEscaped;
    case ScriptDataDoubleEscapedDash;
    case ScriptDataDoubleEscapedDashDash;
    case ScriptDataDoubleEscapedLessThanSign;
    case ScriptDataDoubleEscapeEnd;
    case Dead;

    public function isScriptData(): bool
    {
        return match ($this) {
            self::ScriptData,
            self::ScriptDataLessThanSign,
            self::ScriptDataEndTagOpen,
            self::ScriptDataEndTagName,
            self::ScriptDataEscapeStart,
            self::ScriptDataEscapeStartDash,
            self::ScriptDataEscaped,
            self::ScriptDataEscapedDash,
            self::ScriptDataEscapedDashDash,
            self::ScriptDataEscapedLessThanSign,
            self::ScriptDataEscapedEndTagOpen,
            self::ScriptDataEscapedEndTagName,
            self::ScriptDataDoubleEscapeStart,
            self::ScriptDataDoubleEscaped,
            self::ScriptDataDoubleEscapedDash,
            self::ScriptDataDoubleEscapedDashDash,
            self::ScriptDataDoubleEscapedLessThanSign,
            self::ScriptDataDoubleEscapeEnd => true,
            default => false,
        };
    }
}
