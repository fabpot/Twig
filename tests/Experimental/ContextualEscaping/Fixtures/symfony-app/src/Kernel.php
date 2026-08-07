<?php

namespace App;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class Kernel
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
        $this->container = new Container($twig);
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
}

final class Container
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function has(string $id): bool
    {
        return 'twig' === $id;
    }

    public function get(string $id): Environment
    {
        if (!$this->has($id)) {
            throw new \RuntimeException(\sprintf('Unknown service "%s".', $id));
        }

        return $this->twig;
    }
}
