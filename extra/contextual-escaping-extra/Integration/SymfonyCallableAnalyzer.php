<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Integration;

use Twig\Extra\ContextualEscaping\Analysis\CallableAnalysis;
use Twig\Extra\ContextualEscaping\Analysis\CallableAnalyzerInterface;
use Twig\Extra\ContextualEscaping\Analysis\ContentType;
use Twig\Extra\ContextualEscaping\Analysis\ContentTypeSet;
use Twig\Extra\ContextualEscaping\Analysis\ValueContract;
use Twig\Node\Expression\FunctionExpression;
use Twig\TwigFunction;

/**
 * @internal
 *
 * @experimental
 */
final class SymfonyCallableAnalyzer implements CallableAnalyzerInterface
{
    public function analyze(FunctionExpression $expression): ?CallableAnalysis
    {
        $function = $expression->getAttribute('twig_callable');
        if (!$function instanceof TwigFunction || null === $identity = $this->getCallableIdentity($function->getCallable())) {
            return null;
        }

        $contentType = match ($identity) {
            'Symfony\\UX\\TwigComponent\\Twig\\ComponentRuntime::render' => ContentType::Html,
            'Symfony\\Bridge\\Twig\\Extension\\AssetExtension::getAssetUrl',
            'Symfony\\Bridge\\Twig\\Extension\\RoutingExtension::getPath',
            'Symfony\\Bridge\\Twig\\Extension\\RoutingExtension::getUrl' => ContentType::Url,
            default => null,
        };

        return null === $contentType ? null : new CallableAnalysis(
            new ContentTypeSet([$contentType]),
            new ValueContract($function->getName().'()', $identity, $contentType, 'Symfony integration'),
        );
    }

    private function getCallableIdentity(mixed $callable): ?string
    {
        if (\is_array($callable) && 2 === \count($callable) && \is_string($callable[1])) {
            $class = \is_object($callable[0]) ? $callable[0]::class : $callable[0];

            return \is_string($class) ? ltrim($class, '\\').'::'.$callable[1] : null;
        }
        if (!$callable instanceof \Closure) {
            return null;
        }

        $reflection = new \ReflectionFunction($callable);
        $class = $reflection->getClosureCalledClass()?->getName();

        return null === $class ? null : ltrim($class, '\\').'::'.$reflection->getName();
    }
}
