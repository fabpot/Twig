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

use Twig\Node\Expression\FunctionExpression;
use Twig\TwigFunction;

/**
 * @internal
 *
 * @experimental
 */
final class SymfonyCallableAnalyzer implements ContextualEscapingCallableAnalyzerInterface
{
    public function analyze(FunctionExpression $expression): ?ContextualEscapingCallableAnalysis
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

        return null === $contentType ? null : new ContextualEscapingCallableAnalysis(
            new ContentTypeSet([$contentType]),
            [$function->getName().'()', $identity, $contentType->name],
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
