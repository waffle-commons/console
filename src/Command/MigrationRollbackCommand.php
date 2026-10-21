<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use Throwable;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;
use Waffle\Commons\Contracts\Data\Migration\MigrationRunnerInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;

use function count;
use function filter_var;
use function is_int;
use function sprintf;

use const FILTER_VALIDATE_INT;

/**
 * `waffle db:rollback` — reverts the most recent migrations through their down scripts ([OPS-03]).
 *
 * Like {@see MigrateCommand}, it receives the runner and the pool as contracts; the
 * application wires the concrete `waffle-commons/data` services in its `bin/waffle`.
 * The runner reads every targeted down script before it reverts anything, so a version
 * that cannot be reverted stops the command while the schema is still untouched.
 *
 * Worker hygiene: whatever the outcome, the pool is reset on the way out, so the
 * borrowed handle returns to the idle set before the process exits.
 */
#[WorkerSafe(
    scope: 'cli',
    reason: 'CLI-only: this class is constructed per `bin/waffle` invocation and is never registered in the FrankenPHP worker container, so its mutations cannot cross a request boundary',
)]
final readonly class MigrationRollbackCommand extends AbstractCommand
{
    public function __construct(
        private MigrationRunnerInterface $runner,
        private ResettableInterface $pool,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'db:rollback';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Reverts the most recent migrations through their down scripts.';
    }

    #[\Override]
    public function getHelp(): string
    {
        return (
            "Reverts the --steps most recent applied versions (1 by default), highest\n"
            . "version first: the down script V.down.sql of each version V runs inside its\n"
            . "own transaction and the version leaves the migration log. Every targeted\n"
            . "version is checked first: when one has no down script, an empty one, or no\n"
            . "script on disk at all, nothing is reverted. MySQL, MariaDB and Oracle commit\n"
            . 'each DDL statement at once, so a failing down script can stop half way there.'
        );
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return 'db:rollback [--steps=1]';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $steps = $this->stepsFrom($input);
        if ($steps === null) {
            $output->writeError('[db:rollback] --steps expects a whole number of at least 1, e.g. --steps=2.');

            return ExitCode::USAGE->value;
        }

        $output->writeLine(sprintf('Rolling back up to %d migration(s)…', $steps));

        try {
            $reverted = $this->runner->rollback($steps, static function (string $version) use ($output): void {
                $output->writeLine(sprintf('  reverted  %s', $version));
            });

            if ($reverted === []) {
                $output->writeLine('No migration is applied — nothing to roll back.');

                return ExitCode::SUCCESS->value;
            }

            $output->writeLine(sprintf('Done — %d migration(s) reverted.', count($reverted)));

            return ExitCode::SUCCESS->value;
        } catch (Throwable $failure) {
            $output->writeError(sprintf('[db:rollback] %s', $failure->getMessage()));

            return ExitCode::FAILURE->value;
        } finally {
            $this->pool->reset();
        }
    }

    /**
     * The `--steps` value: 1 when the option is left out, null when it is not a whole number of at least 1.
     */
    private function stepsFrom(InputInterface $input): ?int
    {
        if (!$input->hasOption('steps')) {
            return 1;
        }

        $steps = filter_var($input->getOption('steps'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($steps) ? $steps : null;
    }
}
