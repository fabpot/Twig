<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Audit;

use App\Kernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Twig\Environment;
use Twig\Extra\ContextualEscaping\Analysis\CurrentEscapingSafetyAnalyzer;
use Twig\Loader\FilesystemLoader;

/**
 * @internal
 *
 * @experimental
 */
final class ApplicationKernel extends Kernel
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

        $container->register('twig.contextual_escaping.audit_loader', FilesystemLoader::class)
            ->setArguments([\dirname(__DIR__).'/Resources/templates'])
        ;
        $container->register('twig.contextual_escaping.audit_environment', Environment::class)
            ->setArguments([new Reference('twig.contextual_escaping.audit_loader'), ['autoescape' => 'html', 'strict_variables' => true]])
        ;
        $container->register(FindingAssessor::class);
        $container->register(SourceExcerptBuilder::class);
        $container->register(ReportDataBuilder::class)
            ->setArguments([new Reference(FindingAssessor::class), new Reference(SourceExcerptBuilder::class), '%kernel.project_dir%'])
        ;
        $container->register(HtmlReport::class)
            ->setArguments([new Reference('twig.contextual_escaping.audit_environment'), new Reference(ReportDataBuilder::class), '%kernel.project_dir%/var/contextual-escaping.html'])
        ;
        $container->register(Baseline::class)
            ->setArguments(['%kernel.project_dir%/var/contextual-escaping.json'])
        ;
        $container->register(CurrentEscapingSafetyAnalyzer::class)
            ->setArguments([new Reference('twig')])
        ;
        $container->register(Application::class)
            ->setArguments([
                new Reference('twig'),
                '%twig.default_path%',
                new Reference(HtmlReport::class),
                new Reference(CurrentEscapingSafetyAnalyzer::class),
                new Reference(Baseline::class),
            ])
            ->setPublic(true)
        ;
    }
}
