<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Waffle\Commons\Console\Output\StreamOutput;
use Waffle\Commons\Contracts\Console\Enum\Verbosity;
use WaffleTests\Commons\Console\AbstractTestCase;

#[CoversClass(StreamOutput::class)]
final class StreamOutputTest extends AbstractTestCase
{
    /** @return array{0: resource, 1: resource} */
    private function makeStreams(): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        static::assertIsResource($out);
        static::assertIsResource($err);
        return [$out, $err];
    }

    private function read(mixed $handle): string
    {
        static::assertIsResource($handle);
        rewind($handle);
        $contents = stream_get_contents($handle);
        return is_string($contents) ? $contents : '';
    }

    public function testWriteLineHonoredAtNormalVerbosity(): void
    {
        [$out, $err] = $this->makeStreams();
        new StreamOutput($out, $err)->writeLine('hello');

        static::assertSame('hello' . PHP_EOL, $this->read($out));
    }

    public function testQuietVerbosityDropsNormalMessages(): void
    {
        [$out, $err] = $this->makeStreams();
        $output = new StreamOutput($out, $err);
        $output->setVerbosity(Verbosity::QUIET);
        $output->writeLine('hidden');

        static::assertSame('', $this->read($out));
    }

    public function testDebugVerbosityAllowsEverything(): void
    {
        [$out, $err] = $this->makeStreams();
        $output = new StreamOutput($out, $err);
        $output->setVerbosity(Verbosity::DEBUG);
        $output->writeLine('debug-only', Verbosity::DEBUG);

        static::assertSame('debug-only' . PHP_EOL, $this->read($out));
    }

    public function testErrorAlwaysReachesStderrEvenInQuiet(): void
    {
        [$out, $err] = $this->makeStreams();
        $output = new StreamOutput($out, $err);
        $output->setVerbosity(Verbosity::QUIET);
        $output->writeError('boom');

        static::assertSame('', $this->read($out));
        static::assertSame('boom' . PHP_EOL, $this->read($err));
    }

    public function testWriteOmitsTrailingNewline(): void
    {
        [$out, $err] = $this->makeStreams();
        new StreamOutput($out, $err)->write('partial');

        static::assertSame('partial', $this->read($out));
    }

    public function testInvalidStreamsThrow(): void
    {
        $this->expectException(RuntimeException::class);
        new StreamOutput(false, false); // @phpstan-ignore-line
    }

    public function testWriteRespectsVerbosityThreshold(): void
    {
        [$out, $err] = $this->makeStreams();
        $output = new StreamOutput($out, $err);
        $output->setVerbosity(Verbosity::QUIET);

        $output->write('quiet-drop', Verbosity::NORMAL);
        static::assertSame('', $this->read($out));
    }

    public function testGetVerbosityReturnsConfiguredLevel(): void
    {
        [$out, $err] = $this->makeStreams();
        $output = new StreamOutput($out, $err);
        $output->setVerbosity(Verbosity::VERY_VERBOSE);

        static::assertSame(Verbosity::VERY_VERBOSE, $output->getVerbosity());
    }
}
