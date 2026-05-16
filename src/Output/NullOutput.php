<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Output;

use Waffle\Commons\Contracts\Console\Enum\Verbosity;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * `OutputInterface` that drops every write. Used for silent tests and `--quiet`
 * application invocations.
 *
 * Exposes the captured calls via {@see self::lines()} and {@see self::errors()}
 * so tests can assert behavior without parsing stdout/stderr.
 */
final class NullOutput implements OutputInterface
{
    /** @var list<array{0: string, 1: Verbosity}> */
    private array $lines = [];

    /** @var list<string> */
    private array $errors = [];

    private Verbosity $verbosity = Verbosity::NORMAL;

    #[\Override]
    public function write(string $message, Verbosity $threshold = Verbosity::NORMAL): void
    {
        if (!$this->verbosity->permits($threshold)) {
            return;
        }
        $this->lines[] = [$message, $threshold];
    }

    #[\Override]
    public function writeLine(string $message, Verbosity $threshold = Verbosity::NORMAL): void
    {
        $this->write($message, $threshold);
    }

    #[\Override]
    public function writeError(string $message): void
    {
        $this->errors[] = $message;
    }

    #[\Override]
    public function getVerbosity(): Verbosity
    {
        return $this->verbosity;
    }

    #[\Override]
    public function setVerbosity(Verbosity $level): void
    {
        $this->verbosity = $level;
    }

    /** @return list<array{0: string, 1: Verbosity}> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
