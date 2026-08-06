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
enum CssState
{
    case Selector;
    case Import;
    case PropertyName;
    case Value;
    case DoubleQuotedString;
    case SingleQuotedString;
    case Slash;
    case Comment;
    case CommentStar;
    case UrlStart;
    case UrlUnquoted;
    case UrlDoubleQuoted;
    case UrlSingleQuoted;
    case ImportUrlDoubleQuoted;
    case ImportUrlSingleQuoted;
    case UrlAfterValue;
    case Unknown;
}
