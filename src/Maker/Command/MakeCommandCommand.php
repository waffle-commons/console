<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker\Command;

use Waffle\Commons\Console\Maker\AbstractMakerCommand;
use Waffle\Commons\Console\Maker\TemplateRenderer;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * Console command to scaffold executable CLI commands (RFC-020).
 */
final readonly class MakeCommandCommand extends AbstractMakerCommand
{
    #[\Override]
    public function getName(): string
    {
        return 'make:command';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Génère une classe de commande console Waffle.';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $input->bindArgumentNames(['name']);
        $className = $input->getArgument('name');

        if ($className === null || trim($className) === '') {
            throw new \InvalidArgumentException(
                '[ERREUR] Le nom de la classe de commande est requis (ex. CustomTaskCommand).',
            );
        }

        $commandName = $input->getOption('command-name') ?? 'app:custom-task';
        $force = $input->hasOption('force') || $input->hasOption('f');

        $targetDir = $this->resolveTargetDir($input);
        $resolution = $this->resolveNamespaceAndPath($targetDir, $className);

        $stub = $this->loadStub('command');
        $renderer = new TemplateRenderer();

        $compiled = $renderer->render($stub, [
            'NAMESPACE' => (string) $resolution['namespace'],
            'CLASS_NAME' => (string) $className,
            'COMMAND_NAME' => (string) $commandName,
        ]);

        $this->writeFile($resolution['filepath'], $compiled, $force, $output);

        return ExitCode::SUCCESS->value;
    }
}
