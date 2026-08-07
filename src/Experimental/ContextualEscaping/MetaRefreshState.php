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
enum MetaRefreshState
{
    case Delay;
    case DelayWhitespace;
    case BeforeUrl;
    case UrlPrefix;
    case UrlPrefixWhitespace;
    case UrlStart;
    case Url;
    case UrlDoubleQuoted;
    case UrlSingleQuoted;
    case Done;
    case Unknown;
}
