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
final class MetaElementContext
{
    public function __construct(
        private bool $httpEquivSeen = false,
        private ?bool $refresh = null,
        private bool $contentSeen = false,
        private string $currentAttribute = '',
        private string $currentValue = '',
        private bool $currentDynamic = false,
        private bool $currentPrimaryContent = false,
        private bool $pendingContentInterpolation = false,
        private bool $refreshConflict = false,
    ) {
    }

    public function beginAttribute(string $name): self
    {
        $primaryContent = 'content' === $name && !$this->contentSeen;

        return new self(
            $this->httpEquivSeen,
            $this->refresh,
            $this->contentSeen || 'content' === $name,
            $name,
            currentPrimaryContent: $primaryContent,
            pendingContentInterpolation: $this->pendingContentInterpolation,
            refreshConflict: $this->refreshConflict,
        );
    }

    public function consumeAttributeCharacter(string $character): self
    {
        if ('http-equiv' !== $this->currentAttribute) {
            return $this;
        }

        return new self(
            $this->httpEquivSeen,
            $this->refresh,
            $this->contentSeen,
            $this->currentAttribute,
            '&' === $character ? $this->currentValue : $this->currentValue.$character,
            $this->currentDynamic || '&' === $character,
            $this->currentPrimaryContent,
            $this->pendingContentInterpolation,
            $this->refreshConflict,
        );
    }

    public function recordInterpolation(bool $trusted): self
    {
        return new self(
            $this->httpEquivSeen,
            $this->refresh,
            $this->contentSeen,
            $this->currentAttribute,
            $this->currentValue,
            $this->currentDynamic || 'http-equiv' === $this->currentAttribute,
            $this->currentPrimaryContent,
            $this->pendingContentInterpolation || ($this->currentPrimaryContent && !$this->httpEquivSeen && !$trusted),
            $this->refreshConflict,
        );
    }

    public function finishAttribute(): self
    {
        $httpEquivSeen = $this->httpEquivSeen;
        $refresh = $this->refresh;
        $refreshConflict = $this->refreshConflict;
        if ('http-equiv' === $this->currentAttribute && !$httpEquivSeen) {
            $httpEquivSeen = true;
            $refresh = $this->currentDynamic ? null : 'refresh' === strtolower(trim($this->currentValue));
            $refreshConflict = $refreshConflict || ($this->pendingContentInterpolation && false !== $refresh);
        }

        return new self(
            $httpEquivSeen,
            $refresh,
            $this->contentSeen,
            pendingContentInterpolation: $this->pendingContentInterpolation,
            refreshConflict: $refreshConflict,
        );
    }

    public function getContentAttributeType(): HtmlAttributeType
    {
        if (!$this->currentPrimaryContent || !$this->httpEquivSeen) {
            return HtmlAttributeType::MetaContent;
        }
        if (null === $this->refresh) {
            return HtmlAttributeType::MetaContentUnknown;
        }

        return $this->refresh ? HtmlAttributeType::MetaRefresh : HtmlAttributeType::MetaContent;
    }

    public function hasRefreshConflict(): bool
    {
        return $this->refreshConflict;
    }

    public function merge(self $context): ?self
    {
        if ($this->contentSeen !== $context->contentSeen || $this->currentAttribute !== $context->currentAttribute || $this->currentPrimaryContent !== $context->currentPrimaryContent) {
            return null;
        }

        $httpEquivSeen = $this->httpEquivSeen || $context->httpEquivSeen;
        $refresh = $this->httpEquivSeen === $context->httpEquivSeen && $this->refresh === $context->refresh ? $this->refresh : null;
        $currentDynamic = $this->currentDynamic || $context->currentDynamic || $this->currentValue !== $context->currentValue;
        $currentValue = $currentDynamic ? '' : $this->currentValue;
        $pendingContentInterpolation = $this->pendingContentInterpolation || $context->pendingContentInterpolation;
        $refreshConflict = $this->refreshConflict || $context->refreshConflict || ($pendingContentInterpolation && $httpEquivSeen && false !== $refresh);

        return new self(
            $httpEquivSeen,
            $refresh,
            $this->contentSeen,
            $this->currentAttribute,
            $currentValue,
            $currentDynamic,
            $this->currentPrimaryContent,
            $pendingContentInterpolation,
            $refreshConflict,
        );
    }
}
