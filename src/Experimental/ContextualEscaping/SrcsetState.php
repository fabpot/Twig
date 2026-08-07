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
enum SrcsetState
{
    case BeforeUrl;
    case Url;
    case UrlComma;
    case BeforeDescriptor;
    case Descriptor;
    case DescriptorParenthesized;
    case AfterDescriptor;
    case Unknown;
}
