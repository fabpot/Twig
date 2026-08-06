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
enum DiagnosticCode
{
    case AmbiguousControlFlow;
    case AmbiguousCssContext;
    case AmbiguousJavaScriptContext;
    case AmbiguousUrlContext;
    case CommentInterpolation;
    case CssCommentInterpolation;
    case JavaScriptCommentInterpolation;
    case IncompleteHtmlContext;
    case IncompleteStructuredOutput;
    case MismatchedExplicitEscaping;
    case UnstableLoop;
    case UnsupportedAttributeContext;
    case UnsupportedNode;
    case UnsupportedOutputContext;
    case UnsupportedStructuralInterpolation;
    case UnsupportedTemplateComposition;
}
