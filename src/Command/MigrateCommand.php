<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use Throwable;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;
use Waffle\Commons\Contracts\Data\Migration\MigrationRunnerInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;

use function count;
use function sprintf;

/**
 * `waffle db:migrate` — applies pending SQL migrations (RFC-022 / RFC-012 §3.2).
 *
 * Receives the migration runner and the connection pool via constructor
 * (RFC-012 §4 — no static container access), both typed as contracts so the
 * command stays decoupled from the concrete `waffle-commons/data` implementation;
 * the application wires the concrete services in its `bin/waffle`.
 *
 * Worker hygiene: whatever the outcome, the pool is reset on the way out so the
 * borrowed handle returns to the idle set and the prepared-statement cache is
 * cleared before the process exits — honoring the FrankenPHP statelessness
 * mandate (RFC-022). Migrations are committed per-file inside the runner, so by
 * the time reset() runs there is no open transaction left to roll back.
 */
final readonly class MigrateCommand extends AbstractCommand
{
    public function __construct(
        private MigrationRunnerInterface $runner,
        private ResettableInterface $pool,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'db:migrate';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Applies pending SQL migrations and records them in the migration log.';
    }

    #[\Override]
    public function getHelp(): string
    {
        return (
            "Scans the configured migrations directory and applies every versioned\n"
            . "SQL script not yet recorded in the waffle_migrations table — each inside\n"
            . "its own transaction — printing the applied versions in real time.\n"
            . 'Already-applied migrations are skipped, so the command is safe to re-run.'
        );
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return 'db:migrate';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeLine('Applying database migrations…');

        try {
            $applied = $this->runner->run(static function (string $version) use ($output): void {
                $output->writeLine(sprintf('  applied  %s', $version));
            });

            if ($applied === []) {
                $output->writeLine('Database is already up to date — nothing to apply.');

                return ExitCode::SUCCESS->value;
            }

            $output->writeLine(sprintf('Done — %d migration(s) applied.', count($applied)));

            return ExitCode::SUCCESS->value;
        } catch (Throwable $failure) {
            $output->writeError(sprintf('[db:migrate] %s', $failure->getMessage()));

            return ExitCode::FAILURE->value;
        } finally {
            $this->pool->reset();
        }
    }
}
