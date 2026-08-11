<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Analysis;

/**
 * @internal
 *
 * @experimental
 */
enum ContentType
{
    case PlainText;
    case TrustedInnermost;
    case Html;
    case HtmlAttributeList;
    case HtmlAttribute;
    case HtmlAttributeUnquoted;
    case HtmlRcdata;
    case JavaScriptExpression;
    case JavaScriptString;
    case JavaScriptTemplateString;
    case JavaScriptRegExp;
    case Css;
    case CssString;
    case Url;
    case UrlComponent;
    case Srcset;
}
