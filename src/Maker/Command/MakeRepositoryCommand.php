<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker\Command;

use Waffle\Commons\Console\Maker\AbstractMakerCommand;
use Waffle\Commons\Console\Maker\TemplateRenderer;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * Console command to scaffold a stateless RFC-022 repository together with its
 * pure data-mapper pair (RFC-020) — the persistence twin of `make:entity`.
 */
final readonly class MakeRepositoryCommand extends AbstractMakerCommand
{
    #[\Override]
    public function getName(): string
    {
        return 'make:repository';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Generates a stateless repository and its data mapper pair (RFC-022).';
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

        $requestedName = array_shift($positionals);

        if ($requestedName === null || mb_trim($requestedName) === '') {
            throw new \InvalidArgumentException('[ERROR] Repository name is required (e.g. User or UserRepository).');
        }
        $this->assertValidIdentifier($requestedName, 'repository name');

        $repositoryName = str_ends_with($requestedName, 'Repository') ? $requestedName : $requestedName . 'Repository';
        $entityName = substr($repositoryName, 0, -strlen('Repository'));

        if ($entityName === '') {
            throw new \InvalidArgumentException(
                '[ERROR] Repository name must carry an entity prefix (e.g. UserRepository).',
            );
        }

        $mapperName = $entityName . 'Mapper';
        $force = $input->hasOption('force') || $input->hasOption('f');
        $identity = (string) ($input->getOption('identity') ?? 'id');
        $table = (string) ($input->getOption('table') ?? strtolower($entityName) . 's');

        // $identity lands as a BARE `$entity->{{ IDENTITY }}` property-access
        // expression in the mapper stub (no surrounding quotes at all) — the
        // most dangerous of these slots, so it gets the same identifier
        // grammar as a class name. $table is always quote-wrapped in the
        // stubs, but validated the same way for simplicity/consistency
        // rather than escaping a string literal.
        $this->assertValidIdentifier($identity, 'identity field');
        $this->assertValidIdentifier($table, 'table name');

        // Field names for the mapper projection; the identity always leads.
        // Each name lands as both a quoted array entry (FIELDS_LIST) and a
        // bare `$entity->{name}` property access (ROW_LINES) — same grammar.
        $fieldNames = [$identity];
        foreach ($positionals as $field) {
            $name = explode(':', $field)[0];
            if ($name !== '' && $name !== $identity) {
                $this->assertValidIdentifier($name, 'field name');
                $fieldNames[] = $name;
            }
        }

        $targetDir = $this->resolveTargetDir($input, 'Repository');
        $resolution = $this->resolveNamespaceAndPath($targetDir, $repositoryName);
        $namespace = (string) $resolution['namespace'];

        // Convention: the entity lives in the sibling Entity namespace
        // (src/Repository ⇒ src/Entity); same namespace otherwise.
        $entityNamespace = str_ends_with($namespace, '\\Repository')
            ? substr($namespace, 0, -strlen('\\Repository')) . '\\Entity'
            : $namespace;
        $entityFqcn = $entityNamespace . '\\' . $entityName;

        $fieldsList = implode(', ', array_map(static fn(string $name): string => "'" . $name . "'", $fieldNames));
        $rowLines = implode("\n", array_map(
            static fn(string $name): string => "            '" . $name . "' => \$entity->" . $name . ',',
            $fieldNames,
        ));

        $renderer = new TemplateRenderer();

        $repository = $renderer->render($this->loadStub('repository'), [
            'NAMESPACE' => $namespace,
            'CLASS_NAME' => $repositoryName,
            'ENTITY_FQCN' => $entityFqcn,
            'ENTITY_NAME' => $entityName,
            'MAPPER_NAME' => $mapperName,
            'TABLE' => $table,
            'IDENTITY' => $identity,
        ]);
        $mapper = $renderer->render($this->loadStub('repository_mapper'), [
            'NAMESPACE' => $namespace,
            'CLASS_NAME' => $mapperName,
            'ENTITY_FQCN' => $entityFqcn,
            'ENTITY_NAME' => $entityName,
            'TABLE' => $table,
            'IDENTITY' => $identity,
            'FIELDS_LIST' => $fieldsList,
            'ROW_LINES' => $rowLines,
        ]);

        $this->writeFile($resolution['filepath'], $repository, $force, $output);
        $this->writeFile($targetDir . '/' . $mapperName . '.php', $mapper, $force, $output);

        return ExitCode::SUCCESS->value;
    }
}
