<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Output;

use RuntimeException;
use Waffle\Commons\Contracts\Console\Enum\Verbosity;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * Default `OutputInterface` implementation writing to stdout / stderr.
 *
 * `writeError()` ALWAYS reaches stderr regardless of verbosity (CI/CD pipelines
 * need to see failures). Standard `write` / `writeLine` are gated by Verbosity.
 */
final class StreamOutput implements OutputInterface
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private Verbosity $verbosity = Verbosity::NORMAL;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null)
    {
        $out = $stdout ?? \STDOUT;
        $err = $stderr ?? \STDERR;
        if (!is_resource($out) || !is_resource($err)) {
            throw new RuntimeException('StreamOutput requires open resources for stdout/stderr.');
        }
        $this->stdout = $out;
        $this->stderr = $err;
    }

    #[\Override]
    public function write(string $message, Verbosity $threshold = Verbosity::NORMAL): void
    {
        if (!$this->verbosity->permits($threshold)) {
            return;
        }
        fwrite($this->stdout, $message);
    }

    #[\Override]
    public function writeLine(string $message, Verbosity $threshold = Verbosity::NORMAL): void
    {
        if (!$this->verbosity->permits($threshold)) {
            return;
        }
        fwrite($this->stdout, $message . PHP_EOL);
    }

    #[\Override]
    public function writeError(string $message): void
    {
        fwrite($this->stderr, $message . PHP_EOL);
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
}
