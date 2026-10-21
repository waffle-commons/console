<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use Throwable;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;
use Waffle\Commons\Contracts\Data\Migration\Enum\MigrationStatus;
use Waffle\Commons\Contracts\Data\Migration\MigrationRunnerInterface;
use Waffle\Commons\Contracts\Data\Migration\MigrationState;
use Waffle\Commons\Contracts\Service\ResettableInterface;

use function array_filter;
use function count;
use function max;
use function sprintf;
use function str_pad;
use function strlen;

/**
 * `waffle db:status` — lists every migration version and where it stands ([OPS-03]).
 *
 * Like {@see MigrateCommand}, it receives the runner and the pool as contracts; the
 * application wires the concrete `waffle-commons/data` services in its `bin/waffle`.
 * It never runs a migration script, and it resets the pool on the way out.
 */
#[WorkerSafe(
    scope: 'cli',
    reason: 'CLI-only: this class is constructed per `bin/waffle` invocation and is never registered in the FrankenPHP worker container, so its mutations cannot cross a request boundary',
)]
final readonly class MigrationStatusCommand extends AbstractCommand
{
    private const string NOT_APPLIED = '-';

    public function __construct(
        private MigrationRunnerInterface $runner,
        private ResettableInterface $pool,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'db:status';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Lists every migration version: applied, pending or missing.';
    }

    #[\Override]
    public function getHelp(): string
    {
        return (
            "Lists every version found in the migrations directory or in the migration\n"
            . "log, in version order: applied; pending, which the next db:migrate applies;\n"
            . "or missing, logged although its script is no longer on disk. Each row shows\n"
            . 'when the version was applied and whether a down script lets db:rollback revert it.'
        );
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $states = $this->runner->status();
        } catch (Throwable $failure) {
            $output->writeError(sprintf('[db:status] %s', $failure->getMessage()));

            return ExitCode::FAILURE->value;
        } finally {
            $this->pool->reset();
        }

        if ($states === []) {
            $output->writeLine('No migration found, neither in the migrations directory nor in the migration log.');

            return ExitCode::SUCCESS->value;
        }

        $this->renderTable($states, $output);
        $output->writeLine(sprintf(
            '%d migration(s): %d applied, %d pending, %d missing.',
            count($states),
            self::countOf($states, MigrationStatus::Applied),
            self::countOf($states, MigrationStatus::Pending),
            self::countOf($states, MigrationStatus::Missing),
        ));

        return ExitCode::SUCCESS->value;
    }

    /**
     * @param non-empty-list<MigrationState> $states
     */
    private function renderTable(array $states, OutputInterface $output): void
    {
        $widths = ['status' => strlen('STATUS'), 'version' => strlen('VERSION'), 'applied' => strlen('APPLIED AT')];
        foreach ($states as $state) {
            $widths['status'] = max($widths['status'], strlen($state->status->value));
            $widths['version'] = max($widths['version'], strlen($state->version));
            $widths['applied'] = max($widths['applied'], strlen($state->appliedAt ?? self::NOT_APPLIED));
        }

        $output->writeLine(sprintf(
            '%s  %s  %s  %s',
            str_pad('STATUS', $widths['status']),
            str_pad('VERSION', $widths['version']),
            str_pad('APPLIED AT', $widths['applied']),
            'DOWN',
        ));

        foreach ($states as $state) {
            $output->writeLine(sprintf(
                '%s  %s  %s  %s',
                str_pad($state->status->value, $widths['status']),
                str_pad($state->version, $widths['version']),
                str_pad($state->appliedAt ?? self::NOT_APPLIED, $widths['applied']),
                $state->reversible ? 'yes' : 'no',
            ));
        }
    }

    /**
     * @param list<MigrationState> $states
     */
    private static function countOf(array $states, MigrationStatus $status): int
    {
        return count(array_filter($states, static fn(MigrationState $state): bool => $state->status === $status));
    }
}
