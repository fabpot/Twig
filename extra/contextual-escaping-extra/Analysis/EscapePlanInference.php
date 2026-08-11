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
 * @internal
 *
 * @experimental
 */
final class EscapePlanInference
{
    public function __construct(
        private EscapePlan|DiagnosticCode $result,
        private ?string $diagnosticMessage = null,
    ) {
        if (($result instanceof EscapePlan) === (null !== $diagnosticMessage)) {
            throw new \LogicException('An escape plan inference must contain either a plan or a diagnostic.');
        }
    }

    public function isSuccessful(): bool
    {
        return $this->result instanceof EscapePlan;
    }

    public function getPlan(): EscapePlan
    {
        if (!$this->result instanceof EscapePlan) {
            throw new \LogicException('A failed escape plan inference has no plan.');
        }

        return $this->result;
    }

    public function getDiagnosticCode(): DiagnosticCode
    {
        if ($this->result instanceof EscapePlan) {
            throw new \LogicException('A successful escape plan inference has no diagnostic.');
        }

        return $this->result;
    }

    public function getDiagnosticMessage(): string
    {
        if (null === $this->diagnosticMessage) {
            throw new \LogicException('A successful escape plan inference has no diagnostic.');
        }

        return $this->diagnosticMessage;
    }
}
