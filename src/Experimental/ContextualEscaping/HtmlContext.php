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
        return new self($state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext);
    }

    public function startTag(bool $closingTag, string $tagName = ''): self
    {
        return new self(HtmlState::TagName, $this->element, strtolower($tagName), '', HtmlAttributeType::None, $closingTag);
    }

    public function appendTagName(string $character): self
    {
        return new self($this->state, $this->element, $this->tagName.strtolower($character), $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext);
    }

    public function startAttribute(string $character): self
    {
        return new self(HtmlState::AttributeName, $this->element, $this->tagName, strtolower($character), HtmlAttributeType::None, $this->closingTag);
    }

    public function appendAttributeName(string $character): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName.strtolower($character), $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $this->cssContext);
    }

    public function finishAttributeName(HtmlAttributeType $type, HtmlState $state): self
    {
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
        );
    }

    public function clearAttribute(HtmlState $state): self
    {
        return new self($state, $this->element, $this->tagName, '', HtmlAttributeType::None, $this->closingTag);
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
        return new self($state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, strtolower($candidate), $this->urlPart, $this->javaScriptContext, $this->cssContext);
    }

    public function appendCandidate(string $character): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate.strtolower($character), $this->urlPart, $this->javaScriptContext, $this->cssContext);
    }

    public function resumeSpecial(HtmlState $state): self
    {
        return new self($state, $this->element, javaScriptContext: $this->javaScriptContext, cssContext: $this->cssContext);
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

        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $urlPart, $this->javaScriptContext, $this->cssContext);
    }

    public function afterUrlInterpolation(bool $startsPath): self
    {
        if (HtmlAttributeType::Url !== $this->attributeType || UrlPart::Start !== $this->urlPart) {
            return $this;
        }

        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $startsPath ? UrlPart::Path : UrlPart::Unknown, $this->javaScriptContext, $this->cssContext);
    }

    public function withJavaScriptContext(JavaScriptContext $javaScriptContext): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $javaScriptContext, $this->cssContext);
    }

    public function withCssContext(CssContext $cssContext): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate, $this->urlPart, $this->javaScriptContext, $cssContext);
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
        return $this == $context;
    }

    public function describe(): string
    {
        if (null !== $this->javaScriptContext && ($this->state->isScriptData() || HtmlAttributeType::JavaScript === $this->attributeType)) {
            return 'JavaScript '.$this->javaScriptContext->getState()->name;
        }
        if (null !== $this->cssContext && (HtmlState::RawText === $this->state || HtmlAttributeType::Style === $this->attributeType)) {
            return 'CSS '.$this->cssContext->getState()->name;
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
            HtmlAttributeType::None => 'unknown',
        });
    }

    private function isUrlLeadingSpaceOrControl(string $character): bool
    {
        return 0x20 >= \ord($character);
    }
}
