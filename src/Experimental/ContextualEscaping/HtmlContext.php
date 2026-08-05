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
        return new self($state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate);
    }

    public function startTag(bool $closingTag, string $tagName = ''): self
    {
        return new self(HtmlState::TagName, $this->element, strtolower($tagName), '', HtmlAttributeType::None, $closingTag);
    }

    public function appendTagName(string $character): self
    {
        return new self($this->state, $this->element, $this->tagName.strtolower($character), $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate);
    }

    public function startAttribute(string $character): self
    {
        return new self(HtmlState::AttributeName, $this->element, $this->tagName, strtolower($character), HtmlAttributeType::None, $this->closingTag);
    }

    public function appendAttributeName(string $character): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName.strtolower($character), $this->attributeType, $this->closingTag, $this->candidate);
    }

    public function finishAttributeName(HtmlAttributeType $type, HtmlState $state): self
    {
        return new self($state, $this->element, $this->tagName, $this->attributeName, $type, $this->closingTag);
    }

    public function clearAttribute(HtmlState $state): self
    {
        return new self($state, $this->element, $this->tagName, '', HtmlAttributeType::None, $this->closingTag);
    }

    public function enterElement(HtmlState $state, string $element): self
    {
        return new self($state, strtolower($element));
    }

    public function startCandidate(HtmlState $state, string $candidate = ''): self
    {
        return new self($state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, strtolower($candidate));
    }

    public function appendCandidate(string $character): self
    {
        return new self($this->state, $this->element, $this->tagName, $this->attributeName, $this->attributeType, $this->closingTag, $this->candidate.strtolower($character));
    }

    public function resumeSpecial(HtmlState $state): self
    {
        return new self($state, $this->element);
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
        if ($this->state->isScriptData()) {
            return 'raw text in <script>';
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
            HtmlAttributeType::Url => 'URL',
            HtmlAttributeType::Srcset => 'srcset',
            HtmlAttributeType::Style => 'style',
            HtmlAttributeType::JavaScript => 'JavaScript event',
            HtmlAttributeType::Html => 'HTML',
            HtmlAttributeType::MetaContent => 'meta content',
            HtmlAttributeType::None => 'unknown',
        });
    }
}
