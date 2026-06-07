<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use Throwable;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;
use Waffle\Commons\Contracts\Data\Warmup\DataWarmerInterface;

use function count;
use function sprintf;

/**
 * `waffle data:warmup` — pre-compiles SQR trees and routing tables into
 * OPcache shared memory (Roadmap Beta-3 — "CLI Route & Cache Warmup").
 *
 * Receives the warmers via constructor (RFC-012 §4 — no static container
 * access), typed against the `contracts` interface so the command stays
 * decoupled from the concrete `waffle-commons/data` implementation; the
 * application wires its warmers in `bin/waffle`.
 *
 * Warming is idempotent and strictly CLI-side: it never runs during an HTTP
 * request, and each warmer replaces its artifact atomically, so re-running
 * the command is always safe.
 */
final readonly class DataWarmupCommand extends AbstractCommand
{
    /** @var list<DataWarmerInterface> */
    private array $warmers;

    /** @param list<DataWarmerInterface> $warmers */
    public function __construct(array $warmers)
    {
        $this->warmers = $warmers;
    }

    #[\Override]
    public function getName(): string
    {
        return 'data:warmup';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Pre-compiles SQR trees and routing tables into OPcache shared memory.';
    }

    #[\Override]
    public function getHelp(): string
    {
        return (
            "Invokes every registered warmer: compiled artifacts (parameterised SQL,\n"
            . "routing tables, …) are serialised into PHP cache files and primed into\n"
            . "OPcache shared memory, removing compilation and disk I/O from the first\n"
            . 'live request. Idempotent — safe to re-run after every deploy.'
        );
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return 'data:warmup';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeLine('Warming pre-compiled data artifacts…');

        try {
            $artifacts = [];
            foreach ($this->warmers as $warmer) {
                foreach ($warmer->warmUp() as $artifact) {
                    $artifacts[] = $artifact;
                    $output->writeLine(sprintf('  warmed  %s', $artifact));
                }
            }

            if ($artifacts === []) {
                $output->writeLine('Nothing to warm — no artifact is registered.');

                return ExitCode::SUCCESS->value;
            }

            $output->writeLine(sprintf('Done — %d artifact group(s) warmed.', count($artifacts)));

            return ExitCode::SUCCESS->value;
        } catch (Throwable $failure) {
            $output->writeError(sprintf('[data:warmup] %s', $failure->getMessage()));

            return ExitCode::FAILURE->value;
        }
    }
}
