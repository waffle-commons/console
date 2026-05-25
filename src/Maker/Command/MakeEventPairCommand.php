<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker\Command;

use Waffle\Commons\Console\Maker\AbstractMakerCommand;
use Waffle\Commons\Console\Maker\TemplateRenderer;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * Console command to scaffold coordinated PSR-14 Event and Listener pairs (RFC-020).
 */
final readonly class MakeEventPairCommand extends AbstractMakerCommand
{
    #[\Override]
    public function getName(): string
    {
        return 'make:event-pair';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Génère un couple d\'événement et d\'écouteur PSR-14 auto-câblés.';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $input->bindArgumentNames(['name']);
        $className = $input->getArgument('name');

        if ($className === null || trim($className) === '') {
            throw new \InvalidArgumentException(
                "[ERREUR] Le nom de base de l'événement est requis (ex. UserRegistered).",
            );
        }

        $baseName = preg_replace('/(Event|Listener)$/', '', $className);
        $eventName = $baseName . 'Event';
        $listenerName = $baseName . 'Listener';
        $force = $input->hasOption('force') || $input->hasOption('f');

        $targetDir = $this->resolveTargetDir($input);

        // Structure directory target logic (Event/ and Event/Listener/)
        $eventDir = $targetDir;
        if (!str_ends_with($eventDir, '/Event') && !str_ends_with($eventDir, '/Event/')) {
            $eventDir = rtrim($eventDir, '/') . '/Event';
        }
        $listenerDir = rtrim($eventDir, '/') . '/Listener';

        $eventRes = $this->resolveNamespaceAndPath($eventDir, $eventName);
        $listenerRes = $this->resolveNamespaceAndPath($listenerDir, $listenerName);

        $renderer = new TemplateRenderer();

        // 1. Generate Event
        $eventStub = $this->loadStub('event');
        $eventCompiled = $renderer->render($eventStub, [
            'NAMESPACE' => (string) $eventRes['namespace'],
            'CLASS_NAME' => (string) $eventName,
        ]);
        $this->writeFile($eventRes['filepath'], $eventCompiled, $force, $output);

        // 2. Generate Listener
        $listenerStub = $this->loadStub('listener');
        $listenerCompiled = $renderer->render($listenerStub, [
            'NAMESPACE' => (string) $listenerRes['namespace'],
            'CLASS_NAME' => (string) $listenerName,
            'EVENT_CLASS_NAME' => '\\' . (string) $eventRes['namespace'] . '\\' . (string) $eventName,
        ]);
        $this->writeFile($listenerRes['filepath'], $listenerCompiled, $force, $output);

        return ExitCode::SUCCESS->value;
    }
}
