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

use App\Kernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class ContextualEscapingKernel extends Kernel
{
    public function __construct(string $environment, bool $debug, private string $projectDirectory)
    {
        parent::__construct($environment, $debug);
    }

    public function getProjectDir(): string
    {
        return $this->projectDirectory;
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->register(ContextualEscapingHtmlReport::class)
            ->setArguments(['%kernel.project_dir%/var/contextual-escaping.html'])
        ;
        $container->register(CurrentEscapingSafetyAnalyzer::class)
            ->setArguments([new Reference('twig')])
        ;
        $container->register(ContextualEscapingApplication::class)
            ->setArguments([
                new Reference('twig'),
                '%twig.default_path%',
                new Reference(ContextualEscapingHtmlReport::class),
                new Reference(CurrentEscapingSafetyAnalyzer::class),
            ])
            ->setPublic(true)
        ;
    }
}
