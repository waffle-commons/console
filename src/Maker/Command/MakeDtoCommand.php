<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker\Command;

use Waffle\Commons\Console\Maker\AbstractMakerCommand;
use Waffle\Commons\Console\Maker\Generator\PropertyHookGenerator;
use Waffle\Commons\Console\Maker\TemplateRenderer;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * Console command to scaffold secure Waffle DTOs with native PHP 8.5 property hooks (RFC-020).
 */
final readonly class MakeDtoCommand extends AbstractMakerCommand
{
    #[\Override]
    public function getName(): string
    {
        return 'make:dto';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Génère un DTO final avec propriétés promues et validation par hooks.';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawArgs = $input->getRawArguments();
        $positionals = [];

        foreach ($rawArgs as $arg) {
            if (str_starts_with($arg, '-')) {
                continue;
            }
            $positionals[] = $arg;
        }

        $className = array_shift($positionals);

        if ($className === null || trim($className) === '') {
            throw new \InvalidArgumentException('[ERREUR] Le nom du DTO est requis (ex. UserRegistrationDto).');
        }

        $fields = $positionals;
        $force = $input->hasOption('force') || $input->hasOption('f');

        $targetDir = $this->resolveTargetDir($input);
        $resolution = $this->resolveNamespaceAndPath($targetDir, $className);

        $stub = $this->loadStub('dto');
        $generator = new PropertyHookGenerator();
        $generated = $generator->generate($fields);

        $renderer = new TemplateRenderer();
        $compiled = $renderer->render($stub, [
            'NAMESPACE' => (string) $resolution['namespace'],
            'CLASS_NAME' => (string) $className,
            'PROPERTIES' => (string) $generated['properties'],
            'CONSTRUCTOR_PARAMS' => (string) $generated['constructorParams'],
            'ASSIGNMENTS' => (string) $generated['assignments'],
        ]);

        $this->writeFile($resolution['filepath'], $compiled, $force, $output);

        return ExitCode::SUCCESS->value;
    }
}
