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
 * @experimental
 */
enum DiagnosticCode
{
    case AmbiguousControlFlow;
    case AmbiguousCssContext;
    case AmbiguousJavaScriptContext;
    case AmbiguousMetaRefreshContext;
    case AmbiguousSrcsetContext;
    case AmbiguousUrlContext;
    case CommentInterpolation;
    case CssCommentInterpolation;
    case JavaScriptCommentInterpolation;
    case IncompleteHtmlContext;
    case IncompleteStructuredOutput;
    case MismatchedExplicitEscaping;
    case SyntaxError;
    case UnsafeUrlScheme;
    case UnstableLoop;
    case UnsupportedAttributeContext;
    case UnsupportedNode;
    case UnsupportedOutputContext;
    case UnsupportedStructuralInterpolation;
    case UnsupportedTemplateComposition;
}
