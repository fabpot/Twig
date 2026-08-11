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

use Twig\Extra\ContextualEscaping\Context\CssState;
use Twig\Extra\ContextualEscaping\Context\HtmlAttributeType;
use Twig\Extra\ContextualEscaping\Context\HtmlContext;
use Twig\Extra\ContextualEscaping\Context\HtmlState;
use Twig\Extra\ContextualEscaping\Context\JavaScriptState;
use Twig\Extra\ContextualEscaping\Context\JavaScriptTokenType;
use Twig\Extra\ContextualEscaping\Context\MetaRefreshState;
use Twig\Extra\ContextualEscaping\Context\SrcsetState;
use Twig\Extra\ContextualEscaping\Context\UrlPart;

/**
 * @internal
 *
 * @experimental
 */
final class EscapePlanInferer
{
    public function infer(HtmlContext $context, ContentTypeSet $contentTypes): EscapePlanInference
    {
        if ($contentTypes->contains(ContentType::HtmlAttributeList)) {
            if (HtmlState::BeforeAttributeName === $context->getState()) {
                return new EscapePlanInference(new EscapePlan([]));
            }

            return new EscapePlanInference(DiagnosticCode::UnsupportedOutputContext, \sprintf('An HTML attribute list cannot be rendered in %s.', $context->describe()));
        }
        if ($context->getState()->isScriptData()) {
            return $this->inferJavaScriptPlan($context, $contentTypes, false);
        }
        if (HtmlState::RawText === $context->getState() && null !== $context->getCssContext()) {
            return $this->inferCssPlan($context, $contentTypes, false);
        }

        return match ($context->getState()) {
            HtmlState::Text => new EscapePlanInference(new EscapePlan($contentTypes->contains(ContentType::Html) || $contentTypes->contains(ContentType::TrustedInnermost) ? [] : [EscapeOperation::HtmlText])),
            HtmlState::Rcdata => new EscapePlanInference(new EscapePlan($contentTypes->contains(ContentType::HtmlRcdata) || $contentTypes->contains(ContentType::TrustedInnermost) ? [] : [EscapeOperation::HtmlRcdata])),
            HtmlState::AttributeValueDoubleQuoted, HtmlState::AttributeValueSingleQuoted => $this->inferAttributePlan($context, $contentTypes, false),
            HtmlState::AttributeValueUnquoted => $this->inferAttributePlan($context, $contentTypes, true),
            HtmlState::Comment, HtmlState::CommentStart, HtmlState::CommentStartDash, HtmlState::CommentEndDash, HtmlState::CommentEnd, HtmlState::CommentEndBang => new EscapePlanInference(DiagnosticCode::CommentInterpolation, 'Output expressions inside HTML comments are not supported.'),
            HtmlState::RawText, HtmlState::Plaintext => $this->rejectOutputContext($context),
            default => new EscapePlanInference(DiagnosticCode::UnsupportedStructuralInterpolation, \sprintf('Output expressions in %s are not supported.', $context->describe())),
        };
    }

    /**
     * @param list<EscapeOperation> $operations
     */
    public function cssInterpolationCanChangeContext(ContentTypeSet $contentTypes, array $operations): bool
    {
        if (!$contentTypes->contains(ContentType::TrustedInnermost) && !$contentTypes->contains(ContentType::Css)) {
            return false;
        }

        foreach ($operations as $operation) {
            if (\in_array($operation, [EscapeOperation::CssValue, EscapeOperation::CssString, EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize, EscapeOperation::UrlPath, EscapeOperation::UrlQuery], true)) {
                return false;
            }
        }

        return true;
    }

    private function inferAttributePlan(HtmlContext $context, ContentTypeSet $contentTypes, bool $unquoted): EscapePlanInference
    {
        if (HtmlAttributeType::JavaScript === $context->getAttributeType()) {
            return $this->inferJavaScriptPlan($context, $contentTypes, true, $unquoted);
        }
        if (HtmlAttributeType::Style === $context->getAttributeType()) {
            return $this->inferCssPlan($context, $contentTypes, true, $unquoted);
        }
        if (HtmlAttributeType::Url === $context->getAttributeType()) {
            return $this->inferUrlPlan($context, $contentTypes, $unquoted);
        }
        if (HtmlAttributeType::MetaRefresh === $context->getAttributeType()) {
            return $this->inferMetaRefreshPlan($context, $contentTypes, $unquoted);
        }
        if (HtmlAttributeType::Srcset === $context->getAttributeType()) {
            return $this->inferSrcsetPlan($context, $contentTypes, $unquoted);
        }
        if (HtmlAttributeType::MetaContentUnknown === $context->getAttributeType()) {
            return new EscapePlanInference(DiagnosticCode::AmbiguousMetaRefreshContext, 'The "http-equiv" attribute is dynamic, so the meta content context cannot be determined safely.');
        }

        $attributeContentType = $unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute;
        $trustedInnermost = $contentTypes->contains(ContentType::TrustedInnermost);
        $outerPlan = $contentTypes->contains($attributeContentType) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted)) ? [] : [$unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute];
        $requiredContentType = match ($context->getAttributeType()) {
            HtmlAttributeType::Html => ContentType::Html,
            HtmlAttributeType::UrlList, HtmlAttributeType::MetaContent, HtmlAttributeType::None, HtmlAttributeType::Plain => null,
        };
        if ($trustedInnermost && \in_array($context->getAttributeType(), [HtmlAttributeType::Plain, HtmlAttributeType::MetaContent], true)) {
            return new EscapePlanInference(new EscapePlan([]));
        }
        if (($trustedInnermost && HtmlAttributeType::Html === $context->getAttributeType()) || (null !== $requiredContentType && $contentTypes->contains($requiredContentType))) {
            return new EscapePlanInference(new EscapePlan($outerPlan));
        }

        $analysis = match ($context->getAttributeType()) {
            HtmlAttributeType::UrlList => 'URL list',
            HtmlAttributeType::Html => 'embedded HTML',
            HtmlAttributeType::MetaContent => null,
            HtmlAttributeType::None => 'unknown contextual',
            HtmlAttributeType::Plain => null,
        };
        if (null !== $analysis) {
            return new EscapePlanInference(DiagnosticCode::UnsupportedAttributeContext, \sprintf('Output in the "%s" attribute requires %s analysis, which is not implemented yet.', $context->getAttributeName(), $analysis));
        }

        return new EscapePlanInference(new EscapePlan($outerPlan));
    }

    private function inferUrlPlan(HtmlContext $context, ContentTypeSet $contentTypes, bool $unquoted): EscapePlanInference
    {
        if (\in_array($context->getUrlPart(), [UrlPart::None, UrlPart::Unknown], true)) {
            return new EscapePlanInference(DiagnosticCode::AmbiguousUrlContext, 'Output after a dynamic URL without a static query or fragment delimiter is ambiguous.');
        }

        $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
        $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
        $outerPlan = $outerSafe ? [] : [$outerOperation];
        if ($contentTypes->contains(ContentType::TrustedInnermost) || $contentTypes->contains(ContentType::UrlComponent)) {
            return new EscapePlanInference(new EscapePlan($outerPlan));
        }
        if (UrlPart::Start === $context->getUrlPart() && $contentTypes->contains(ContentType::Url)) {
            return new EscapePlanInference(new EscapePlan($outerPlan));
        }

        $operations = match ($context->getUrlPart()) {
            UrlPart::Start => [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize],
            UrlPart::Path => [EscapeOperation::UrlPath],
            UrlPart::QueryOrFragment => [EscapeOperation::UrlQuery],
        };
        if (!$outerPlan) {
            $outerPlan = [$outerOperation];
        }

        return new EscapePlanInference(new EscapePlan([...$operations, ...$outerPlan]));
    }

    private function inferSrcsetPlan(HtmlContext $context, ContentTypeSet $contentTypes, bool $unquoted): EscapePlanInference
    {
        $srcsetContext = $context->getSrcsetContext();
        if (null === $srcsetContext) {
            return $this->rejectOutputContext($context);
        }

        $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
        $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
        $outerPlan = $outerSafe ? [] : [$outerOperation];
        if ($contentTypes->contains(ContentType::TrustedInnermost)) {
            return new EscapePlanInference(new EscapePlan($outerPlan));
        }

        if (SrcsetState::BeforeUrl === $srcsetContext->getState()) {
            if ($contentTypes->contains(ContentType::Srcset) || $contentTypes->contains(ContentType::UrlComponent)) {
                return new EscapePlanInference(new EscapePlan($outerPlan));
            }
            if ($contentTypes->contains(ContentType::Url)) {
                return new EscapePlanInference(new EscapePlan([EscapeOperation::UrlNormalize, $outerOperation]));
            }

            return new EscapePlanInference(new EscapePlan([EscapeOperation::SrcsetFilter, $outerOperation]));
        }

        if (SrcsetState::Url === $srcsetContext->getState()) {
            if ($contentTypes->contains(ContentType::Srcset) || \in_array($srcsetContext->getUrlPart(), [UrlPart::None, UrlPart::Unknown], true)) {
                return new EscapePlanInference(DiagnosticCode::AmbiguousSrcsetContext, 'Output in an ambiguous srcset URL context is not supported.');
            }
            if ($contentTypes->contains(ContentType::UrlComponent)) {
                return new EscapePlanInference(new EscapePlan($outerPlan));
            }

            $operations = match ($srcsetContext->getUrlPart()) {
                UrlPart::Start => [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize],
                UrlPart::Path => [EscapeOperation::UrlPath],
                UrlPart::QueryOrFragment => [EscapeOperation::UrlQuery],
            };
            $operations[] = $outerOperation;

            return new EscapePlanInference(new EscapePlan($operations));
        }

        $message = match ($srcsetContext->getState()) {
            SrcsetState::UrlComma => 'Output immediately after a comma in a srcset URL is ambiguous because the comma may be part of the URL or terminate the candidate.',
            SrcsetState::BeforeDescriptor, SrcsetState::Descriptor, SrcsetState::DescriptorParenthesized, SrcsetState::AfterDescriptor => 'Output expressions in srcset descriptors are not supported.',
            SrcsetState::Unknown => 'Output after dynamic or character-reference srcset content is ambiguous.',
        };

        return new EscapePlanInference(DiagnosticCode::AmbiguousSrcsetContext, $message);
    }

    private function inferMetaRefreshPlan(HtmlContext $context, ContentTypeSet $contentTypes, bool $unquoted): EscapePlanInference
    {
        $metaRefreshContext = $context->getMetaRefreshContext();
        if (null === $metaRefreshContext) {
            return $this->rejectOutputContext($context);
        }
        if (\in_array($metaRefreshContext->getState(), [MetaRefreshState::DelayWhitespace, MetaRefreshState::BeforeUrl, MetaRefreshState::UrlPrefix, MetaRefreshState::UrlPrefixWhitespace, MetaRefreshState::Unknown], true)) {
            return new EscapePlanInference(DiagnosticCode::AmbiguousMetaRefreshContext, 'Output in an ambiguous meta refresh delimiter, URL prefix, or character-reference context is not supported.');
        }

        $trusted = $contentTypes->contains(ContentType::TrustedInnermost);
        if (MetaRefreshState::Delay === $metaRefreshContext->getState()) {
            $operations = $trusted ? [] : [EscapeOperation::MetaRefreshDelay];
        } elseif (MetaRefreshState::Done === $metaRefreshContext->getState()) {
            $operations = [];
        } elseif (\in_array($metaRefreshContext->getState(), [MetaRefreshState::UrlStart, MetaRefreshState::Url, MetaRefreshState::UrlDoubleQuoted, MetaRefreshState::UrlSingleQuoted], true)) {
            $urlPart = $metaRefreshContext->getUrlPart();
            if (\in_array($urlPart, [UrlPart::None, UrlPart::Unknown], true)) {
                return new EscapePlanInference(DiagnosticCode::AmbiguousUrlContext, 'Output after a dynamic meta refresh URL without a static query or fragment delimiter is ambiguous.');
            }
            if ($trusted || $contentTypes->contains(ContentType::UrlComponent)) {
                $operations = [];
            } elseif (UrlPart::Start === $urlPart && $contentTypes->contains(ContentType::Url)) {
                $operations = [EscapeOperation::UrlNormalize];
            } else {
                $operations = match ($urlPart) {
                    UrlPart::Start => [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize],
                    UrlPart::Path => [EscapeOperation::UrlPath],
                    UrlPart::QueryOrFragment => [EscapeOperation::UrlQuery],
                };
            }
        } else {
            throw new \LogicException(\sprintf('Unexpected meta refresh state "%s".', $metaRefreshContext->getState()->name));
        }

        $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
        $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
        if ($operations || !$outerSafe) {
            $operations[] = $outerOperation;
        }

        return new EscapePlanInference(new EscapePlan($operations));
    }

    private function inferCssPlan(HtmlContext $context, ContentTypeSet $contentTypes, bool $attribute, bool $unquoted = false): EscapePlanInference
    {
        $cssContext = $context->getCssContext();
        if (null === $cssContext) {
            return $this->rejectOutputContext($context);
        }
        if (null !== $cssContext->getEscapeDigits() || '' !== $cssContext->getToken() || \in_array($cssContext->getState(), [CssState::Slash, CssState::UrlAfterValue, CssState::Unknown], true)) {
            return new EscapePlanInference(DiagnosticCode::AmbiguousCssContext, 'Output in an ambiguous CSS token, escape, or URL context is not supported.');
        }
        if (\in_array($cssContext->getState(), [CssState::Comment, CssState::CommentStar], true)) {
            return new EscapePlanInference(DiagnosticCode::CssCommentInterpolation, 'Output expressions inside CSS comments are not supported.');
        }
        if (\in_array($cssContext->getState(), [CssState::Selector, CssState::Import, CssState::PropertyName], true)) {
            if (!$contentTypes->contains(ContentType::TrustedInnermost) && !$contentTypes->contains(ContentType::Css)) {
                return new EscapePlanInference(DiagnosticCode::UnsupportedOutputContext, \sprintf('Output expressions in CSS %s contexts are not supported.', match ($cssContext->getState()) {
                    CssState::Selector => 'selector',
                    CssState::Import => 'import',
                    CssState::PropertyName => 'property-name',
                }));
            }

            $operation = null;
        } elseif (CssState::Value === $cssContext->getState()) {
            $operation = $contentTypes->contains(ContentType::TrustedInnermost) || $contentTypes->contains(ContentType::Css) ? null : EscapeOperation::CssValue;
        } elseif (\in_array($cssContext->getState(), [CssState::DoubleQuotedString, CssState::SingleQuotedString], true)) {
            $operation = $contentTypes->contains(ContentType::TrustedInnermost) || $contentTypes->contains(ContentType::CssString) ? null : EscapeOperation::CssString;
        } elseif (\in_array($cssContext->getState(), [CssState::UrlStart, CssState::UrlUnquoted, CssState::UrlDoubleQuoted, CssState::UrlSingleQuoted, CssState::ImportUrlDoubleQuoted, CssState::ImportUrlSingleQuoted], true)) {
            return $this->inferCssUrlPlan($context, $contentTypes, $attribute, $unquoted);
        } else {
            throw new \LogicException(\sprintf('Unexpected CSS state "%s".', $cssContext->getState()->name));
        }

        $operations = null === $operation ? [] : [$operation];
        if ($attribute) {
            $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
            $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
            if (null !== $operation || !$outerSafe) {
                $operations[] = $outerOperation;
            }
        }

        return new EscapePlanInference(new EscapePlan($operations));
    }

    private function inferCssUrlPlan(HtmlContext $context, ContentTypeSet $contentTypes, bool $attribute, bool $unquoted): EscapePlanInference
    {
        $urlPart = $context->getCssContext()?->getUrlPart() ?? UrlPart::None;
        if (\in_array($urlPart, [UrlPart::None, UrlPart::Unknown], true)) {
            return new EscapePlanInference(DiagnosticCode::AmbiguousUrlContext, 'Output after a dynamic CSS URL without a static query or fragment delimiter is ambiguous.');
        }

        if ($contentTypes->contains(ContentType::TrustedInnermost)) {
            $operations = [];
        } else {
            if ($contentTypes->contains(ContentType::UrlComponent)) {
                $operations = [];
            } elseif (UrlPart::Start === $urlPart && $contentTypes->contains(ContentType::Url)) {
                $operations = [EscapeOperation::UrlNormalize];
            } else {
                $operations = match ($urlPart) {
                    UrlPart::Start => [EscapeOperation::UrlSchemeFilter, EscapeOperation::UrlNormalize],
                    UrlPart::Path => [EscapeOperation::UrlPath],
                    UrlPart::QueryOrFragment => [EscapeOperation::UrlQuery],
                };
            }
            $operations[] = EscapeOperation::CssString;
        }

        if ($attribute) {
            $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
            $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
            if ($operations || !$outerSafe) {
                $operations[] = $outerOperation;
            }
        }

        return new EscapePlanInference(new EscapePlan($operations));
    }

    private function inferJavaScriptPlan(HtmlContext $context, ContentTypeSet $contentTypes, bool $attribute, bool $unquoted = false): EscapePlanInference
    {
        $javaScriptContext = $context->getJavaScriptContext();
        if (null === $javaScriptContext) {
            return $this->rejectOutputContext($context);
        }
        if ($javaScriptContext->isEscaped() || $javaScriptContext->hasTemplateDollar() || (JavaScriptState::Code === $javaScriptContext->getState() && JavaScriptTokenType::None !== $javaScriptContext->getTokenType()) || \in_array($javaScriptContext->getState(), [JavaScriptState::Slash, JavaScriptState::LessThan, JavaScriptState::HtmlOpenCommentBang, JavaScriptState::HtmlOpenCommentDash, JavaScriptState::Minus, JavaScriptState::HtmlCloseCommentDashDash, JavaScriptState::Unknown], true)) {
            return new EscapePlanInference(DiagnosticCode::AmbiguousJavaScriptContext, 'Output in an ambiguous JavaScript token or slash context is not supported.');
        }
        if (\in_array($javaScriptContext->getState(), [JavaScriptState::LineComment, JavaScriptState::BlockComment, JavaScriptState::BlockCommentStar], true)) {
            return new EscapePlanInference(DiagnosticCode::JavaScriptCommentInterpolation, 'Output expressions inside JavaScript comments are not supported.');
        }

        $trusted = $contentTypes->contains(ContentType::TrustedInnermost);
        $operation = match ($javaScriptContext->getState()) {
            JavaScriptState::Code => $trusted || $contentTypes->contains(ContentType::JavaScriptExpression) ? null : EscapeOperation::JavaScriptValue,
            JavaScriptState::DoubleQuotedString, JavaScriptState::SingleQuotedString => $trusted || $contentTypes->contains(ContentType::JavaScriptString) ? null : EscapeOperation::JavaScriptString,
            JavaScriptState::TemplateString => $trusted || $contentTypes->contains(ContentType::JavaScriptTemplateString) ? null : EscapeOperation::JavaScriptTemplateString,
            JavaScriptState::RegExp => $trusted || $contentTypes->contains(ContentType::JavaScriptRegExp) ? null : EscapeOperation::JavaScriptRegExp,
        };
        $operations = null === $operation ? [] : [$operation];
        if ($attribute) {
            $outerOperation = $unquoted ? EscapeOperation::HtmlAttributeUnquoted : EscapeOperation::HtmlAttribute;
            $outerSafe = $contentTypes->contains($unquoted ? ContentType::HtmlAttributeUnquoted : ContentType::HtmlAttribute) || (!$unquoted && $contentTypes->contains(ContentType::HtmlAttributeUnquoted));
            if (null !== $operation || !$outerSafe) {
                $operations[] = $outerOperation;
            }
        }

        return new EscapePlanInference(new EscapePlan($operations));
    }

    private function rejectOutputContext(HtmlContext $context): EscapePlanInference
    {
        return new EscapePlanInference(DiagnosticCode::UnsupportedOutputContext, \sprintf('Output in %s requires language-specific analysis, which is not implemented yet.', $context->describe()));
    }
}
