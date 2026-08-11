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
enum HtmlAttributeType
{
    case None;
    case Plain;
    case Url;
    case UrlList;
    case Srcset;
    case Style;
    case JavaScript;
    case Html;
    case MetaContent;
    case MetaRefresh;
    case MetaContentUnknown;
}
