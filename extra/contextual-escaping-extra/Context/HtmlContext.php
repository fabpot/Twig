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
final class HtmlContext
{
    public function __construct(
        private HtmlState $state = HtmlState::Text,
        private ?string $element = null,
        private string $tagName = '',
        private string $attributeName = '',
        private HtmlAttributeType $attributeType = HtmlAttributeType::None,
        private bool $closingTag = false,
        private string $candidate = '',
        private UrlPart $urlPart = UrlPart::None,
        private ?JavaScriptContext $javaScriptContext = null,
        private ?CssContext $cssContext = null,
        private ?MetaElementContext $metaElementContext = null,
        private ?MetaRefreshContext $metaRefreshContext = null,
        private ?SrcsetContext $srcsetContext = null,
    ) {
    }

    public function getState(): HtmlState
    {
        return $this->state;
    }

    public function getElement(): ?string
    {
        return $this->element;
    }

    public function getTagName(): string
    {
        return $this->tagName;
    }

    public function getAttributeName(): string
    {
        return $this->attributeName;
    }

    public function getAttributeType(): HtmlAttributeType
    {
        return $this->attributeType;
    }

    public function getUrlPart(): UrlPart
    {
        return $this->urlPart;
    }

    public function getJavaScriptContext(): ?JavaScriptContext
    {
        return $this->javaScriptContext;
    }

    public function getCssContext(): ?CssContext
    {
        return $this->cssContext;
    }

    public function getMetaRefreshContext(): ?MetaRefreshContext
    {
        return $this->metaRefreshContext;
    }

    public function getSrcsetContext(): ?SrcsetContext
    {
        return $this->srcsetContext;
    }

    public function isClosingTag(): bool
    {
        return $this->closingTag;
    }

    public function getCandidate(): string
    {
        return $this->candidate;
    }

    public function withState(HtmlState $state): self
    {
        return new self($state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext, $this->metaElementContext, $this->metaRefreshContext, $this->srcsetContext);
    }

    public function startTag(bool $closingTag, string $tagName = ''): self
    {
        return new self(HtmlState::TagName, $this->element, strtolower($tagName), '', HtmlAttributeType::None, $closingTag);
    }

    public function appendTagName(string $character): self
    {
        return new self($this->state, $this->element, $this->tagName.strtolower($character), $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext, $this->metaElementContext, $this->metaRefreshContext, $this->srcsetContext);
    }

    public function startAttribute(string $character): self
    {
        return new self(
            HtmlState::AttributeName,
            $this->element,
            $this->tagName,
            strtolower($character),
            HtmlAttributeType::None,
            $this->closingTag,
            metaElementContext: $this->finishMetaElementAttribute(),
        );
    }

    public function appendAttributeName(string $character): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName.strtolower($character), $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext, $this->metaElementContext, $this->metaRefreshContext, $this->srcsetContext);
    }

    public function finishAttributeName(HtmlAttributeType $type, HtmlState $state): self
    {
        $metaElementContext = $this->metaElementContext;
        if ('meta' === $this->tagName) {
            $metaElementContext = ($metaElementContext ?? new MetaElementContext())->beginAttribute($this->attributeName);
            if ('content' === $this->attributeName) {
                $type = $metaElementContext->getContentAttributeType();
            }
        }

        return new self(
            $state,
            $this->element,
            $this->tagName,
            $this->attributeName,
            $type,
            $this->closingTag,
            '',
            HtmlAttributeType::Url === $type ? UrlPart::Start : UrlPart::None,
            HtmlAttributeType::JavaScript === $type ? new JavaScriptContext() : null,
            HtmlAttributeType::Style === $type ? CssContext::forDeclarationList() : null,
            $metaElementContext,
            HtmlAttributeType::MetaRefresh === $type ? new MetaRefreshContext() : null,
            HtmlAttributeType::Srcset === $type ? new SrcsetContext() : null,
        );
    }

    public function clearAttribute(HtmlState $state): self
    {
        return new self($state, $this->element, $this->tagName, '', HtmlAttributeType::None, $this->closingTag, metaElementContext: $this->finishMetaElementAttribute());
    }

    public function enterElement(HtmlState $state, string $element): self
    {
        return new self(
            $state,
            strtolower($element),
            javaScriptContext: HtmlState::ScriptData === $state ? new JavaScriptContext() : null,
            cssContext: HtmlState::RawText === $state && 'style' === strtolower($element) ? CssContext::forStylesheet() : null,
        );
    }

    public function startCandidate(HtmlState $state, string $candidate = ''): self
    {
        return new self($state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, strtolower($candidate), $this->urlPart, $this->javaScriptContext, $this->cssContext, $this->metaElementContext, $this->metaRefreshContext, $this->srcsetContext);
    }

    public function appendCandidate(string $character): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate.strtolower($character), $this->urlPart, $this->javaScriptContext, $this->cssContext, $this->metaElementContext, $this->metaRefreshContext, $this->srcsetContext);
    }

    public function resumeSpecial(HtmlState $state): self
    {
        return new self($state, $this->element, javaScriptContext: $this->javaScriptContext, cssContext: $this->cssContext, metaElementContext: $this->metaElementContext, metaRefreshContext: $this->metaRefreshContext, srcsetContext: $this->srcsetContext);
    }

    public function consumeUrlCharacter(string $character): self
    {
        if (HtmlAttributeType::Url !== $this->attributeType) {
            return $this;
        }

        $urlPart = $this->urlPart;
        if ('?' === $character || '#' === $character) {
            $urlPart = UrlPart::QueryOrFragment;
        } elseif ('&' === $character && UrlPart::QueryOrFragment !== $urlPart) {
            $urlPart = UrlPart::Unknown;
        } elseif (UrlPart::Start === $urlPart && !$this->isUrlLeadingSpaceOrControl($character)) {
            $urlPart = UrlPart::Path;
        }

        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $urlPart, $this->javaScriptContext, $this->cssContext, $this->metaElementContext, $this->metaRefreshContext, $this->srcsetContext);
    }

    public function afterUrlInterpolation(bool $startsPath): self
    {
        if (HtmlAttributeType::Url !== $this->attributeType || UrlPart::Start !== $this->urlPart) {
            return $this;
        }

        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $startsPath ? UrlPart::Path : UrlPart::Unknown, $this->javaScriptContext, $this->cssContext, $this->metaElementContext, $this->metaRefreshContext, $this->srcsetContext);
    }

    public function withJavaScriptContext(JavaScriptContext $javaScriptContext): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $javaScriptContext, $this->cssContext, $this->metaElementContext, $this->metaRefreshContext, $this->srcsetContext);
    }

    public function withCssContext(CssContext $cssContext): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $cssContext, $this->metaElementContext, $this->metaRefreshContext, $this->srcsetContext);
    }

    public function withMetaRefreshContext(MetaRefreshContext $metaRefreshContext): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext, $this->metaElementContext, $metaRefreshContext, $this->srcsetContext);
    }

    public function withSrcsetContext(SrcsetContext $srcsetContext): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext, $this->metaElementContext, $this->metaRefreshContext, $srcsetContext);
    }

    public function consumeMetaElementAttributeCharacter(string $character): self
    {
        if (null === $this->metaElementContext) {
            return $this;
        }

        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext, $this->metaElementContext->consumeAttributeCharacter($character), $this->metaRefreshContext, $this->srcsetContext);
    }

    public function recordAttributeInterpolation(bool $trusted): self
    {
        if (null === $this->metaElementContext) {
            return $this;
        }

        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext, $this->metaElementContext->recordInterpolation($trusted), $this->metaRefreshContext, $this->srcsetContext);
    }

    public function finishAttribute(): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext, $this->finishMetaElementAttribute(), $this->metaRefreshContext, $this->srcsetContext);
    }

    public function hasMetaRefreshConflict(): bool
    {
        return $this->metaElementContext?->hasRefreshConflict() ?? false;
    }

    public function resolveJavaScriptPendingTokenForInterpolation(): self
    {
        if (null === $this->javaScriptContext) {
            return $this;
        }
        if (JavaScriptState::Minus === $this->javaScriptContext->getState()) {
            return $this->withJavaScriptContext($this->javaScriptContext->withState(JavaScriptState::Code, JavaScriptSlashContext::RegExp));
        }
        if (JavaScriptState::Slash !== $this->javaScriptContext->getState()) {
            return $this;
        }

        $javaScriptContext = match ($this->javaScriptContext->getSlashContext()) {
            JavaScriptSlashContext::RegExp => $this->javaScriptContext->withState(JavaScriptState::RegExp),
            JavaScriptSlashContext::Division => $this->javaScriptContext->withState(JavaScriptState::Code, JavaScriptSlashContext::RegExp),
            JavaScriptSlashContext::Unknown => $this->javaScriptContext->withState(JavaScriptState::Unknown, JavaScriptSlashContext::Unknown),
        };

        return $this->withJavaScriptContext($javaScriptContext);
    }

    public function afterJavaScriptInterpolation(bool $valueLike): self
    {
        if (null === $this->javaScriptContext || JavaScriptState::Code !== $this->javaScriptContext->getState()) {
            return $this;
        }

        $javaScriptContext = $this->javaScriptContext
            ->withToken(JavaScriptTokenType::None, '')
            ->withSlashContext($valueLike ? JavaScriptSlashContext::Division : JavaScriptSlashContext::Unknown);

        return $this->withJavaScriptContext($javaScriptContext);
    }

    public function resolveCssPendingTokenForInterpolation(): self
    {
        return null === $this->cssContext ? $this : $this->withCssContext($this->cssContext->resolvePendingTokenForInterpolation());
    }

    public function afterCssUrlInterpolation(bool $startsPath): self
    {
        return null === $this->cssContext ? $this : $this->withCssContext($this->cssContext->afterUrlInterpolation($startsPath));
    }

    public function afterCssInterpolation(bool $unknown): self
    {
        return null === $this->cssContext ? $this : $this->withCssContext($this->cssContext->afterInterpolation($unknown));
    }

    public function afterMetaRefreshUrlInterpolation(bool $startsPath): self
    {
        return null === $this->metaRefreshContext ? $this : $this->withMetaRefreshContext($this->metaRefreshContext->afterUrlInterpolation($startsPath));
    }

    public function afterMetaRefreshInterpolation(bool $delay, bool $unknown): self
    {
        return null === $this->metaRefreshContext ? $this : $this->withMetaRefreshContext($this->metaRefreshContext->afterInterpolation($delay, $unknown));
    }

    public function afterSrcsetInterpolation(bool $trusted, bool $srcset, bool $url, bool $urlComponent): self
    {
        return null === $this->srcsetContext ? $this : $this->withSrcsetContext($this->srcsetContext->afterInterpolation($trusted, $srcset, $url, $urlComponent));
    }

    public function nudgeAttributeValue(): self
    {
        if (HtmlState::BeforeAttributeValue !== $this->state) {
            return $this;
        }

        return $this->withState(HtmlState::AttributeValueUnquoted);
    }

    public function toText(): self
    {
        return new self();
    }

    public function toDead(): self
    {
        return new self(HtmlState::Dead);
    }

    public function equals(self $context): bool
    {
        return null !== $this->merge($context);
    }

    public function merge(self $context): ?self
    {
        if (
            $this->state !== $context->state
            || $this->element !== $context->element
            || $this->tagName !== $context->tagName
            || $this->attributeName !== $context->attributeName
            || $this->attributeType !== $context->attributeType
            || $this->closingTag !== $context->closingTag
            || $this->candidate !== $context->candidate
            || $this->urlPart !== $context->urlPart
            || $this->javaScriptContext != $context->javaScriptContext
            || $this->cssContext != $context->cssContext
            || $this->metaRefreshContext != $context->metaRefreshContext
            || $this->srcsetContext != $context->srcsetContext
        ) {
            return null;
        }

        if (null === $this->metaElementContext || null === $context->metaElementContext) {
            if ($this->metaElementContext !== $context->metaElementContext) {
                return null;
            }
            $metaElementContext = null;
        } elseif (null === $metaElementContext = $this->metaElementContext->merge($context->metaElementContext)) {
            return null;
        }

        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext, $metaElementContext, $this->metaRefreshContext, $this->srcsetContext);
    }

    public function describe(): string
    {
        if (null !== $this->javaScriptContext && ($this->state->isScriptData() || HtmlAttributeType::JavaScript === $this->attributeType)) {
            return 'JavaScript '.$this->javaScriptContext->getState()->name;
        }
        if (null !== $this->cssContext && (HtmlState::RawText === $this->state || HtmlAttributeType::Style === $this->attributeType)) {
            return 'CSS '.$this->cssContext->getState()->name;
        }
        if (null !== $this->metaRefreshContext) {
            return 'meta refresh '.$this->metaRefreshContext->getState()->name;
        }
        if (null !== $this->srcsetContext) {
            return 'srcset '.match ($this->srcsetContext->getState()) {
                SrcsetState::BeforeUrl => 'candidate start',
                SrcsetState::Url => match ($this->srcsetContext->getUrlPart()) {
                    UrlPart::Start => 'URL start',
                    UrlPart::Path => 'URL path',
                    UrlPart::QueryOrFragment => 'URL query or fragment',
                    UrlPart::None, UrlPart::Unknown => 'ambiguous URL',
                },
                SrcsetState::UrlComma => 'ambiguous URL comma',
                SrcsetState::BeforeDescriptor => 'descriptor start',
                SrcsetState::Descriptor => 'descriptor',
                SrcsetState::DescriptorParenthesized => 'parenthesized descriptor',
                SrcsetState::AfterDescriptor => 'descriptor separator',
                SrcsetState::Unknown => 'ambiguous content',
            };
        }

        return match ($this->state) {
            HtmlState::Text => 'HTML text',
            HtmlState::AttributeValueDoubleQuoted => $this->describeAttribute('double-quoted'),
            HtmlState::AttributeValueSingleQuoted => $this->describeAttribute('single-quoted'),
            HtmlState::AttributeValueUnquoted, HtmlState::BeforeAttributeValue => $this->describeAttribute('unquoted'),
            HtmlState::Comment, HtmlState::CommentStart, HtmlState::CommentStartDash, HtmlState::CommentEndDash, HtmlState::CommentEnd, HtmlState::CommentEndBang => 'an HTML comment',
            HtmlState::Rcdata, HtmlState::RcdataLessThanSign, HtmlState::RcdataEndTagOpen, HtmlState::RcdataEndTagName => \sprintf('RCDATA in <%s>', $this->element),
            HtmlState::RawText, HtmlState::RawTextLessThanSign, HtmlState::RawTextEndTagOpen, HtmlState::RawTextEndTagName, HtmlState::Plaintext => \sprintf('raw text in <%s>', $this->element),
            HtmlState::Dead => 'an unknown context',
            default => 'HTML structure ('.$this->state->name.')',
        };
    }

    private function describeAttribute(string $delimiter): string
    {
        return \sprintf('a %s %s attribute', $delimiter, match ($this->attributeType) {
            HtmlAttributeType::Plain => 'plain HTML',
            HtmlAttributeType::Url => match ($this->urlPart) {
                UrlPart::Start => 'URL start',
                UrlPart::Path => 'URL path',
                UrlPart::QueryOrFragment => 'URL query or fragment',
                UrlPart::Unknown => 'ambiguous URL',
                UrlPart::None => 'URL',
            },
            HtmlAttributeType::UrlList => 'URL list',
            HtmlAttributeType::Srcset => 'srcset',
            HtmlAttributeType::Style => 'style',
            HtmlAttributeType::JavaScript => 'JavaScript event',
            HtmlAttributeType::Html => 'HTML',
            HtmlAttributeType::MetaContent => 'meta content',
            HtmlAttributeType::MetaRefresh => 'meta refresh',
            HtmlAttributeType::MetaContentUnknown => 'ambiguous meta content',
            HtmlAttributeType::None => 'unknown',
        });
    }

    private function finishMetaElementAttribute(): ?MetaElementContext
    {
        return $this->metaElementContext?->finishAttribute();
    }

    private function isUrlLeadingSpaceOrControl(string $character): bool
    {
        return 0x20 >= \ord($character);
    }
}
