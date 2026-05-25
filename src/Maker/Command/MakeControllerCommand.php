<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker\Command;

use Waffle\Commons\Console\Maker\AbstractMakerCommand;
use Waffle\Commons\Console\Maker\TemplateRenderer;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * Console command to scaffold Waffle HTTP Controllers (RFC-020).
 */
final readonly class MakeControllerCommand extends AbstractMakerCommand
{
    #[\Override]
    public function getName(): string
    {
        return 'make:controller';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Generates an immutable Waffle Controller.';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $input->bindArgumentNames(['name']);
        $className = $input->getArgument('name');

        if ($className === null || trim($className) === '') {
            throw new \InvalidArgumentException('[ERROR] Controller name is required (e.g. HomeController).');
        }

        $route = $input->getOption('route') ?? '/';
        $priority = $input->getOption('priority') ?? '0';
        $force = $input->hasOption('force') || $input->hasOption('f');

        $targetDir = $this->resolveTargetDir($input, 'Controller');
        $resolution = $this->resolveNamespaceAndPath($targetDir, $className);

        $stub = $this->loadStub('controller');
        $renderer = new TemplateRenderer();

        $compiled = $renderer->render($stub, [
            'NAMESPACE' => (string) $resolution['namespace'],
            'CLASS_NAME' => (string) $className,
            'ROUTE' => (string) $route,
            'PRIORITY' => (string) $priority,
        ]);

        $this->writeFile($resolution['filepath'], $compiled, $force, $output);

        return ExitCode::SUCCESS->value;
    }
}
