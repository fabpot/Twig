#!/usr/bin/env php
<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\Dotenv\Dotenv;
use Twig\Experimental\ContextualEscaping\ContextualEscapingApplication;
use Twig\Experimental\ContextualEscaping\ContextualEscapingKernel;

try {
    exit(lintSymfonyApplication($argv));
} catch (Throwable $error) {
    fwrite(\STDERR, $error->getMessage()."\n");
    exit(2);
}

/**
 * @param list<string> $arguments
 */
function lintSymfonyApplication(array $arguments): int
{
    if (2 !== count($arguments)) {
        fwrite(\STDERR, "Usage: php bin/lint_contextual_escaping.php /path/to/symfony-app\n");

        return 2;
    }

    $projectDirectory = realpath($arguments[1]);
    if (false === $projectDirectory || !is_dir($projectDirectory)) {
        throw new InvalidArgumentException(sprintf('The Symfony application directory "%s" does not exist.', $arguments[1]));
    }

    $autoload = $projectDirectory.'/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException(sprintf('The Symfony application dependencies are not installed in "%s".', $projectDirectory));
    }

    require_once $autoload;

    $contextualEscapingDirectory = dirname(__DIR__).'/src/Experimental/ContextualEscaping/';
    spl_autoload_register(static function (string $class) use ($contextualEscapingDirectory): void {
        $prefix = 'Twig\\Experimental\\ContextualEscaping\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $file = $contextualEscapingDirectory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }, true, true);

    $workingDirectory = getcwd();
    chdir($projectDirectory);
    $kernel = null;

    try {
        $bootstrap = $projectDirectory.'/config/bootstrap.php';
        if (is_file($bootstrap)) {
            require $bootstrap;
        } elseif (class_exists(Dotenv::class) && is_file($projectDirectory.'/.env')) {
            (new Dotenv())->bootEnv($projectDirectory.'/.env');
        }

        $kernelClass = 'App\\Kernel';
        if (!class_exists($kernelClass)) {
            throw new RuntimeException(sprintf('The "%s" Symfony kernel class cannot be loaded.', $kernelClass));
        }
        if ((new ReflectionClass($kernelClass))->isFinal()) {
            throw new RuntimeException(sprintf('The "%s" Symfony kernel class must not be final.', $kernelClass));
        }

        require_once __DIR__.'/Internal/ContextualEscapingHtmlReport.php';
        require_once __DIR__.'/Internal/ContextualEscapingApplication.php';
        require_once __DIR__.'/Internal/ContextualEscapingKernel.php';

        $environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
        $debug = filter_var($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? 'prod' !== $environment, \FILTER_VALIDATE_BOOL);
        $kernel = new ContextualEscapingKernel($environment, $debug, $projectDirectory);
        $kernel->boot();
        $container = $kernel->getContainer();

        if (!$container->has(ContextualEscapingApplication::class)) {
            throw new RuntimeException('The contextual escaping application service cannot be loaded.');
        }

        $application = $container->get(ContextualEscapingApplication::class);
        if (!$application instanceof ContextualEscapingApplication) {
            throw new RuntimeException('The contextual escaping application service is invalid.');
        }

        return $application->run();
    } finally {
        if (null !== $kernel) {
            $kernel->shutdown();
        }
        if (false !== $workingDirectory) {
            chdir($workingDirectory);
        }
    }
}
