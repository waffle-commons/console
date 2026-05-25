<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker\Command;

use Waffle\Commons\Console\Maker\AbstractMakerCommand;
use Waffle\Commons\Console\Maker\TemplateRenderer;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * Console command to scaffold PSR-15 Middlewares (RFC-020).
 */
final readonly class MakeMiddlewareCommand extends AbstractMakerCommand
{
    #[\Override]
    public function getName(): string
    {
        return 'make:middleware';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Génère un middleware PSR-15 Waffle.';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $input->bindArgumentNames(['name']);
        $className = $input->getArgument('name');

        if ($className === null || trim($className) === '') {
            throw new \InvalidArgumentException('[ERREUR] Le nom du middleware est requis (ex. AuthMiddleware).');
        }

        $force = $input->hasOption('force') || $input->hasOption('f');

        $targetDir = $this->resolveTargetDir($input);
        $resolution = $this->resolveNamespaceAndPath($targetDir, $className);

        $stub = $this->loadStub('middleware');
        $renderer = new TemplateRenderer();

        $compiled = $renderer->render($stub, [
            'NAMESPACE' => (string) $resolution['namespace'],
            'CLASS_NAME' => (string) $className,
        ]);

        $this->writeFile($resolution['filepath'], $compiled, $force, $output);

        return ExitCode::SUCCESS->value;
    }
}
