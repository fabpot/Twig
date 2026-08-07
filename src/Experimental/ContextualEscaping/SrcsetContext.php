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
final class SrcsetContext
{
    public function __construct(
        private SrcsetState $state = SrcsetState::BeforeUrl,
        private UrlPart $urlPart = UrlPart::None,
    ) {
    }

    public function getState(): SrcsetState
    {
        return $this->state;
    }

    public function getUrlPart(): UrlPart
    {
        return $this->urlPart;
    }

    public function withState(SrcsetState $state, UrlPart $urlPart = UrlPart::None): self
    {
        return new self($state, $urlPart);
    }

    public function withUrlPart(UrlPart $urlPart): self
    {
        return new self($this->state, $urlPart);
    }

    public function afterInterpolation(bool $trusted, bool $srcset, bool $url, bool $urlComponent): self
    {
        if ($trusted) {
            return $this->withState(SrcsetState::Unknown);
        }

        if (SrcsetState::BeforeUrl === $this->state) {
            if ($urlComponent) {
                return $this->withState(SrcsetState::Url, UrlPart::Path);
            }
            if ($url) {
                return $this->withState(SrcsetState::Url, UrlPart::Unknown);
            }

            return $this->withState(SrcsetState::BeforeDescriptor);
        }

        if (SrcsetState::Url === $this->state && !$srcset) {
            return $this;
        }

        return $this->withState(SrcsetState::Unknown);
    }
}
