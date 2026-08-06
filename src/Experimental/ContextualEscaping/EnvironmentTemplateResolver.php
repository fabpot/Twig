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

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Node\ModuleNode;

/**
 * @internal
 *
 * @experimental
 */
final class EnvironmentTemplateResolver implements TemplateResolverInterface
{
    /** @var array<string, ModuleNode|null> */
    private array $modules = [];

    public function __construct(
        private Environment $environment,
    ) {
    }

    public function resolve(string $name, string $from): ?ModuleNode
    {
        if (\array_key_exists($name, $this->modules)) {
            return $this->modules[$name];
        }

        try {
            $source = $this->environment->getLoader()->getSourceContext($name);
        } catch (LoaderError) {
            return $this->modules[$name] = null;
        }

        return $this->modules[$name] = $this->environment->parse($this->environment->tokenize($source));
    }
}
