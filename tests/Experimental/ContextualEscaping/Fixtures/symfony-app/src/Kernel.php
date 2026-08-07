<?php

namespace Symfony\Component\DependencyInjection;

final class Reference
{
    public function __construct(
        private string $id,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }
}

final class Definition
{
    private array $arguments = [];
    private bool $public = false;

    public function __construct(
        private string $class,
    ) {
    }

    public function setArguments(array $arguments): self
    {
        $this->arguments = $arguments;

        return $this;
    }

    public function setPublic(bool $public): self
    {
        $this->public = $public;

        return $this;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }
}

final class ContainerBuilder
{
    private array $definitions = [];

    public function __construct(
        private array $services,
        private array $parameters,
    ) {
    }

    public function register(string $id, ?string $class = null): Definition
    {
        return $this->definitions[$id] = new Definition($class ?? $id);
    }

    public function createContainer(): Container
    {
        return new Container($this->definitions, $this->services, $this->parameters);
    }
}

final class Container
{
    private array $initialized = [];

    public function __construct(
        private array $definitions,
        private array $services,
        private array $parameters,
    ) {
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]) && $this->definitions[$id]->isPublic();
    }

    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new \RuntimeException(\sprintf('Unknown service "%s".', $id));
        }

        if (isset($this->initialized[$id])) {
            return $this->initialized[$id];
        }

        $definition = $this->definitions[$id];
        $arguments = array_map(function (mixed $argument): mixed {
            if ($argument instanceof Reference) {
                return $this->services[$argument->getId()];
            }
            if (\is_string($argument) && str_starts_with($argument, '%') && str_ends_with($argument, '%')) {
                return $this->parameters[trim($argument, '%')];
            }

            return $argument;
        }, $definition->getArguments());
        $class = $definition->getClass();

        return $this->initialized[$id] = new $class(...$arguments);
    }
}

namespace App;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class Kernel
{
    private ?Container $container = null;

    public function __construct(string $environment, bool $debug)
    {
        if ('test' !== $environment || $debug) {
            throw new \RuntimeException('The test application was booted with the wrong environment.');
        }
    }

    public function boot(): void
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__).'/templates'), ['optimizations' => 0]);
        $twig->addFunction(new TwigFunction('application_value', static fn (): string => 'value'));
        $container = new ContainerBuilder(
            ['twig' => $twig],
            ['twig.default_path' => \dirname(__DIR__).'/templates'],
        );
        $this->build($container);
        $this->container = $container->createContainer();
    }

    public function getContainer(): Container
    {
        if (null === $this->container) {
            throw new \RuntimeException('The test application is not booted.');
        }

        return $this->container;
    }

    public function shutdown(): void
    {
        $this->container = null;
    }

    protected function build(ContainerBuilder $container): void
    {
    }
}
