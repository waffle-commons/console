<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use Throwable;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\Enum\Verbosity;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * `waffle cache:clear` — flushes the cache (RFC-012 §3.2).
 *
 * Receives the cache via constructor (RFC-012 §4 — no static container access).
 * Returns `ExitCode::SUCCESS` on success, `ExitCode::FAILURE` if the backend
 * raised an exception during clear.
 */
final readonly class CacheClearCommand extends AbstractCommand
{
    public function __construct(
        private CacheInterface $cache,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'cache:clear';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Flushes the application cache.';
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return 'cache:clear [-v|--verbose]';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->write('Clearing cache… ', Verbosity::NORMAL);

        try {
            $ok = $this->cache->clear();
        } catch (Throwable $e) {
            $output->writeError(sprintf('Cache backend failed: %s', $e->getMessage()));
            return ExitCode::FAILURE->value;
        }

        if (!$ok) {
            $output->writeError('Cache backend reported a failure.');
            return ExitCode::FAILURE->value;
        }

        $output->writeLine('done.', Verbosity::NORMAL);
        return ExitCode::SUCCESS->value;
    }
}
