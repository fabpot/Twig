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
final class CssContext
{
    public function __construct(
        private CssState $state,
        private CssState $returnState,
        private string $token = '',
        private int $parenthesisDepth = 0,
        private int $declarationDepth = 0,
        private ?int $escapeDigits = null,
        private UrlPart $urlPart = UrlPart::None,
        private bool $declarationList = false,
    ) {
    }

    public static function forStylesheet(): self
    {
        return new self(CssState::Selector, CssState::Selector);
    }

    public static function forDeclarationList(): self
    {
        return new self(CssState::PropertyName, CssState::PropertyName, declarationDepth: 1, declarationList: true);
    }

    public function getState(): CssState
    {
        return $this->state;
    }

    public function getReturnState(): CssState
    {
        return $this->returnState;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getParenthesisDepth(): int
    {
        return $this->parenthesisDepth;
    }

    public function getDeclarationDepth(): int
    {
        return $this->declarationDepth;
    }

    public function getEscapeDigits(): ?int
    {
        return $this->escapeDigits;
    }

    public function getUrlPart(): UrlPart
    {
        return $this->urlPart;
    }

    public function isDeclarationList(): bool
    {
        return $this->declarationList;
    }

    public function withState(CssState $state, ?CssState $returnState = null): self
    {
        return new self($state, $returnState ?? $this->returnState, '', $this->parenthesisDepth, $this->declarationDepth, null, $this->urlPart, $this->declarationList);
    }

    public function withToken(string $token): self
    {
        return new self($this->state, $this->returnState, $token, $this->parenthesisDepth, $this->declarationDepth, $this->escapeDigits, $this->urlPart, $this->declarationList);
    }

    public function withParenthesisDepth(int $parenthesisDepth): self
    {
        return new self($this->state, $this->returnState, $this->token, $parenthesisDepth, $this->declarationDepth, $this->escapeDigits, $this->urlPart, $this->declarationList);
    }

    public function withDeclarationDepth(int $declarationDepth): self
    {
        return new self($this->state, $this->returnState, $this->token, $this->parenthesisDepth, $declarationDepth, $this->escapeDigits, $this->urlPart, $this->declarationList);
    }

    public function withEscapeDigits(?int $escapeDigits): self
    {
        return new self($this->state, $this->returnState, $this->token, $this->parenthesisDepth, $this->declarationDepth, $escapeDigits, $this->urlPart, $this->declarationList);
    }

    public function enterUrl(): self
    {
        return new self(CssState::UrlStart, $this->state, '', $this->parenthesisDepth, $this->declarationDepth, null, UrlPart::Start, $this->declarationList);
    }

    public function enterImportUrl(CssState $state): self
    {
        return new self($state, CssState::Import, '', $this->parenthesisDepth, $this->declarationDepth, null, UrlPart::Start, $this->declarationList);
    }

    public function leaveUrl(): self
    {
        return new self($this->returnState, $this->returnState, '', $this->parenthesisDepth, $this->declarationDepth, declarationList: $this->declarationList);
    }

    public function withUrlPart(UrlPart $urlPart): self
    {
        return new self($this->state, $this->returnState, $this->token, $this->parenthesisDepth, $this->declarationDepth, $this->escapeDigits, $urlPart, $this->declarationList);
    }

    public function resolvePendingTokenForInterpolation(): self
    {
        if (CssState::Slash === $this->state) {
            return $this->withState($this->returnState, $this->returnState);
        }

        return 6 === $this->escapeDigits ? $this->withEscapeDigits(null) : $this;
    }

    public function afterUrlInterpolation(bool $startsPath): self
    {
        if (UrlPart::Start !== $this->urlPart || !\in_array($this->state, [CssState::UrlStart, CssState::UrlUnquoted, CssState::UrlDoubleQuoted, CssState::UrlSingleQuoted, CssState::ImportUrlDoubleQuoted, CssState::ImportUrlSingleQuoted], true)) {
            return $this;
        }

        return $this->withUrlPart($startsPath ? UrlPart::Path : UrlPart::Unknown);
    }

    public function afterInterpolation(bool $unknown): self
    {
        return $unknown ? $this->withState(CssState::Unknown) : $this;
    }
}
