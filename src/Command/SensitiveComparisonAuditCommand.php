<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use Waffle\Commons\Console\Audit\SensitiveComparisonScanner;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * `waffle security:compare-audit [path]` — SEC-03 timing-safe-comparison gate.
 *
 * Closes the SEC-03 requirement that Mago cannot express (no custom-rule API):
 * walks every `*.php` file under the target path (default `src`) and fails when a
 * naive `===` / `!==` compares two runtime values where one is named like a secret
 * — such a comparison must use {@see \hash_equals()} to be timing-safe.
 *
 * Thin by design: depends ONLY on `waffle-commons/contracts` (Input/Output/ExitCode)
 * plus the in-component {@see SensitiveComparisonScanner}. Distinct from
 * {@see SecurityAuditCommand} (route ABAC/CSRF) and {@see MemoryAuditCommand} (Igor).
 *
 * Exit codes: SUCCESS (0) clean, USAGE (64) at least one finding (CI-failing),
 * NO_INPUT (66) the target path does not exist.
 */
final readonly class SensitiveComparisonAuditCommand extends AbstractCommand
{
    public const string NAME = 'security:compare-audit';

    private const string DEFAULT_PATH = 'src';

    public function __construct(
        private SensitiveComparisonScanner $scanner = new SensitiveComparisonScanner(),
    ) {}

    #[\Override]
    public function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Bans naive === / !== on sensitive call sites; mandates hash_equals() (SEC-03).';
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return self::NAME . ' [path]';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->resolvePath($input);
        if (!is_dir($path)) {
            $output->writeError(sprintf('Path not found: %s', $path));

            return ExitCode::NO_INPUT->value;
        }

        $output->writeLine('Timing-safe comparison audit (SEC-03):');
        $output->writeLine('');

        $findings = $this->scanner->scanDirectory($path);

        foreach ($findings as $finding) {
            $output->writeError(sprintf(
                'TIMING-UNSAFE COMPARISON: %s:%d  %s  (%s) — use hash_equals().',
                $finding->file,
                $finding->line,
                $finding->snippet,
                $finding->operator,
            ));
        }

        $output->writeLine('');
        if ($findings === []) {
            $output->writeLine(sprintf('No timing-unsafe comparisons found under "%s".', $path));

            return ExitCode::SUCCESS->value;
        }

        $output->writeError(sprintf('%d timing-unsafe comparison(s) found.', count($findings)));

        return ExitCode::USAGE->value;
    }

    /**
     * First positional token (the scan path), or the default `src`. Read from raw
     * argv since the application does not bind named arguments before dispatch.
     */
    private function resolvePath(InputInterface $input): string
    {
        foreach ($input->getRawArguments() as $argument) {
            if (!str_starts_with($argument, '-')) {
                return $argument;
            }
        }

        return self::DEFAULT_PATH;
    }
}
