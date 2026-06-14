<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker\Command;

use Waffle\Commons\Console\Maker\AbstractMakerCommand;
use Waffle\Commons\Console\Maker\TemplateRenderer;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * Console command to scaffold secure PSR-18 HTTP Clients (RFC-020).
 */
final readonly class MakeHttpClientCommand extends AbstractMakerCommand
{
    #[\Override]
    public function getName(): string
    {
        return 'make:http-client';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Generates a secure PSR-18 HTTP Client.';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $input->bindArgumentNames(['name']);
        $className = $input->getArgument('name');

        if ($className === null || mb_trim($className) === '') {
            throw new \InvalidArgumentException('[ERROR] HTTP Client name is required (e.g. UserApiClient).');
        }

        $baseUri = $input->getOption('base-uri') ?? 'http://api.internal';
        $force = $input->hasOption('force') || $input->hasOption('f');

        $targetDir = $this->resolveTargetDir($input, 'Service');
        $resolution = $this->resolveNamespaceAndPath($targetDir, $className);

        $stub = $this->loadStub('http_client');
        $renderer = new TemplateRenderer();

        $compiled = $renderer->render($stub, [
            'NAMESPACE' => (string) $resolution['namespace'],
            'CLASS_NAME' => (string) $className,
            'BASE_URI' => (string) $baseUri,
        ]);

        $this->writeFile($resolution['filepath'], $compiled, $force, $output);

        return ExitCode::SUCCESS->value;
    }
}
