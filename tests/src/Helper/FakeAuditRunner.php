<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper;

use Closure;
use Waffle\Commons\Contracts\Runtime\AuditRunnerInterface;

/**
 * In-memory {@see AuditRunnerInterface} for command tests: records the
 * invocation and replays canned output lines, spawning no real process.
 */
final class FakeAuditRunner implements AuditRunnerInterface
{
    public private(set) ?string $lastScriptPath = null;

    public private(set) ?string $lastWorkingDirectory = null;

    /** @var list<string>|null Arguments from the most recent run(), or null if never run. */
    public private(set) ?array $lastArguments = null;

    /**
     * @param list<array{0: string, 1: bool}> $output Lines to replay (text, isError).
     */
    public function __construct(
        private readonly array $output = [],
        private readonly int $exitCode = 0,
    ) {}

    #[\Override]
    public function run(string $scriptPath, string $workingDirectory, array $arguments, Closure $onLine): int
    {
        $this->lastScriptPath = $scriptPath;
        $this->lastWorkingDirectory = $workingDirectory;
        $this->lastArguments = $arguments;
        foreach ($this->output as [$line, $isError]) {
            $onLine($line, $isError);
        }

        return $this->exitCode;
    }
}
