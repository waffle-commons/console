<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;
use Waffle\Commons\Contracts\Runtime\AuditRunnerInterface;

/**
 * `waffle igor:audit` — runs the monorepo-wide Igor memory-leak & state-mutation
 * audit (`igor.sh`) and streams its output to the console (RFC-012 §3.2).
 *
 * Thin by design: it depends ONLY on `waffle-commons/contracts`
 * ({@see AuditRunnerInterface} + Input/Output). The OS-level execution lives in
 * the `waffle-commons/runtime` concrete (`ProcessAuditRunner`), injected by the
 * application's `bin/waffle` — so `console` gains no dependency edge, exactly
 * like `db:migrate` consumes `MigrationRunnerInterface`. Distinct from
 * {@see SecurityAuditCommand}, which audits ABAC/CSRF route authorization.
 *
 * Exit codes: SUCCESS (0) audit passed, FAILURE (1) dangerous shared state,
 * NO_INPUT (66) the audit script is missing.
 */
final readonly class MemoryAuditCommand extends AbstractCommand
{
    public const string NAME = 'igor:audit';

    private const string SCRIPT = 'igor.sh';

    public function __construct(
        private AuditRunnerInterface $runner,
        private string $projectRoot,
        private bool $localByDefault = false,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Audits every component for memory leaks and shared-state mutations (Igor).';
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return self::NAME . ' [--local] [--silent|-s]';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $scriptPath = rtrim($this->projectRoot, '/') . '/' . self::SCRIPT;
        if (!is_file($scriptPath)) {
            $output->writeError(sprintf('Audit script not found: %s', $scriptPath));

            return ExitCode::NO_INPUT->value;
        }

        $arguments = [];
        if ($input->hasOption('local') || $this->localByDefault) {
            $arguments[] = '--local';
        }
        if ($input->hasOption('silent') || $input->hasOption('s')) {
            $arguments[] = '--silent';
        }

        $output->writeLine('Igor memory-leak & state-mutation audit');
        $output->writeLine('');

        $exitCode = $this->runner->run($scriptPath, $this->projectRoot, $arguments, static function (
            string $line,
            bool $isError,
        ) use ($output): void {
            if ($isError) {
                $output->writeError($line);

                return;
            }
            $output->writeLine($line);
        });

        $output->writeLine('');
        if ($exitCode === ExitCode::SUCCESS->value) {
            $output->writeLine('Audit passed: no leaks or shared-state mutations detected.');

            return ExitCode::SUCCESS->value;
        }

        $output->writeError(sprintf('Audit failed (exit code %d): dangerous shared state detected.', $exitCode));

        return ExitCode::FAILURE->value;
    }
}
