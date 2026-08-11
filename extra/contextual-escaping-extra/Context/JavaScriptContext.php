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
final class JavaScriptContext
{
    /**
     * @param list<int> $templateExpressionDepths
     */
    public function __construct(
        private JavaScriptState $state = JavaScriptState::Code,
        private JavaScriptSlashContext $slashContext = JavaScriptSlashContext::RegExp,
        private bool $escaped = false,
        private bool $regExpCharacterClass = false,
        private JavaScriptTokenType $tokenType = JavaScriptTokenType::None,
        private string $token = '',
        private bool $templateDollar = false,
        private array $templateExpressionDepths = [],
    ) {
    }

    public function getState(): JavaScriptState
    {
        return $this->state;
    }

    public function getSlashContext(): JavaScriptSlashContext
    {
        return $this->slashContext;
    }

    public function isEscaped(): bool
    {
        return $this->escaped;
    }

    public function isRegExpCharacterClass(): bool
    {
        return $this->regExpCharacterClass;
    }

    public function getTokenType(): JavaScriptTokenType
    {
        return $this->tokenType;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function hasTemplateDollar(): bool
    {
        return $this->templateDollar;
    }

    public function withState(JavaScriptState $state, ?JavaScriptSlashContext $slashContext = null): self
    {
        return new self($state, $slashContext ?? $this->slashContext, false, false, JavaScriptTokenType::None, '', false, $this->templateExpressionDepths);
    }

    public function withSlashContext(JavaScriptSlashContext $slashContext): self
    {
        return new self($this->state, $slashContext, $this->escaped, $this->regExpCharacterClass, $this->tokenType, $this->token, $this->templateDollar, $this->templateExpressionDepths);
    }

    public function withEscaped(bool $escaped): self
    {
        return new self($this->state, $this->slashContext, $escaped, $this->regExpCharacterClass, $this->tokenType, $this->token, $this->templateDollar, $this->templateExpressionDepths);
    }

    public function withRegExpCharacterClass(bool $regExpCharacterClass): self
    {
        return new self($this->state, $this->slashContext, $this->escaped, $regExpCharacterClass, $this->tokenType, $this->token, $this->templateDollar, $this->templateExpressionDepths);
    }

    public function withToken(JavaScriptTokenType $tokenType, string $token): self
    {
        return new self($this->state, $this->slashContext, $this->escaped, $this->regExpCharacterClass, $tokenType, $token, $this->templateDollar, $this->templateExpressionDepths);
    }

    public function withTemplateDollar(bool $templateDollar): self
    {
        return new self($this->state, $this->slashContext, $this->escaped, $this->regExpCharacterClass, $this->tokenType, $this->token, $templateDollar, $this->templateExpressionDepths);
    }

    public function enterTemplateExpression(): self
    {
        $depths = $this->templateExpressionDepths;
        $depths[] = 0;

        return new self(JavaScriptState::Code, JavaScriptSlashContext::RegExp, false, false, JavaScriptTokenType::None, '', false, $depths);
    }

    public function isInTemplateExpression(): bool
    {
        return [] !== $this->templateExpressionDepths;
    }

    public function increaseTemplateExpressionDepth(): self
    {
        $depths = $this->templateExpressionDepths;
        ++$depths[\count($depths) - 1];

        return new self($this->state, $this->slashContext, $this->escaped, $this->regExpCharacterClass, $this->tokenType, $this->token, $this->templateDollar, $depths);
    }

    public function closeTemplateExpressionBrace(): self
    {
        $depths = $this->templateExpressionDepths;
        $index = \count($depths) - 1;
        if (0 === $depths[$index]) {
            array_pop($depths);

            return new self(JavaScriptState::TemplateString, JavaScriptSlashContext::Division, false, false, JavaScriptTokenType::None, '', false, $depths);
        }
        --$depths[$index];

        return new self($this->state, JavaScriptSlashContext::Division, false, false, JavaScriptTokenType::None, '', false, $depths);
    }
}
