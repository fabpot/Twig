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
enum JavaScriptState
{
    case Code;
    case DoubleQuotedString;
    case SingleQuotedString;
    case TemplateString;
    case RegExp;
    case Slash;
    case LessThan;
    case HtmlOpenCommentBang;
    case HtmlOpenCommentDash;
    case Minus;
    case HtmlCloseCommentDashDash;
    case LineComment;
    case BlockComment;
    case BlockCommentStar;
    case Unknown;
}
