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
final class Diagnostic
{
    public function __construct(
        private DiagnosticCode $code,
        private string $message,
        private int $templateLine,
        private ?string $templateName,
    ) {
    }

    public function getCode(): DiagnosticCode
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getTemplateLine(): int
    {
        return $this->templateLine;
    }

    public function getTemplateName(): ?string
    {
        return $this->templateName;
    }
}
