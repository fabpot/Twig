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
enum EscapeOperation
{
    case HtmlText;
    case HtmlAttribute;
    case HtmlAttributeUnquoted;
    case HtmlRcdata;
    case JavaScriptValue;
    case JavaScriptString;
    case JavaScriptTemplateString;
    case JavaScriptRegExp;
    case CssValue;
    case CssString;
    case MetaRefreshDelay;
    case UrlSchemeFilter;
    case UrlNormalize;
    case UrlPath;
    case UrlQuery;
}
