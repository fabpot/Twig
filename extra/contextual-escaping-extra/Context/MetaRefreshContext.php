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
final class MetaRefreshContext
{
    public function __construct(
        private MetaRefreshState $state = MetaRefreshState::Delay,
        private bool $hasDelay = false,
        private string $token = '',
        private UrlPart $urlPart = UrlPart::None,
    ) {
    }

    public function getState(): MetaRefreshState
    {
        return $this->state;
    }

    public function hasDelay(): bool
    {
        return $this->hasDelay;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getUrlPart(): UrlPart
    {
        return $this->urlPart;
    }

    public function withState(MetaRefreshState $state, UrlPart $urlPart = UrlPart::None): self
    {
        return new self($state, $this->hasDelay, urlPart: $urlPart);
    }

    public function withDelay(): self
    {
        return new self($this->state, true, $this->token, $this->urlPart);
    }

    public function withToken(string $token): self
    {
        return new self($this->state, $this->hasDelay, $token, $this->urlPart);
    }

    public function withUrlPart(UrlPart $urlPart): self
    {
        return new self($this->state, $this->hasDelay, $this->token, $urlPart);
    }

    public function afterUrlInterpolation(bool $startsPath): self
    {
        if (UrlPart::Start !== $this->urlPart || !\in_array($this->state, [MetaRefreshState::BeforeUrl, MetaRefreshState::UrlStart, MetaRefreshState::Url, MetaRefreshState::UrlDoubleQuoted, MetaRefreshState::UrlSingleQuoted], true)) {
            return $this;
        }

        $urlPart = $startsPath ? UrlPart::Path : UrlPart::Unknown;

        return \in_array($this->state, [MetaRefreshState::BeforeUrl, MetaRefreshState::UrlStart], true) ? $this->withState(MetaRefreshState::Url, $urlPart) : $this->withUrlPart($urlPart);
    }

    public function afterInterpolation(bool $delay, bool $unknown): self
    {
        if ($unknown && MetaRefreshState::Done !== $this->state) {
            return $this->withState(MetaRefreshState::Unknown);
        }

        return $delay ? $this->withDelay() : $this;
    }
}
