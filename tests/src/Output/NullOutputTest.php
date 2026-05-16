<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\Verbosity;
use WaffleTests\Commons\Console\AbstractTestCase;

#[CoversClass(NullOutput::class)]
final class NullOutputTest extends AbstractTestCase
{
    public function testCapturesLines(): void
    {
        $output = new NullOutput();
        $output->writeLine('hello');
        $output->writeLine('world');

        static::assertCount(2, $output->lines());
        static::assertSame('hello', $output->lines()[0][0]);
    }

    public function testCapturesErrorsIndependently(): void
    {
        $output = new NullOutput();
        $output->writeLine('ok');
        $output->writeError('boom');

        static::assertCount(1, $output->lines());
        static::assertCount(1, $output->errors());
        static::assertSame('boom', $output->errors()[0]);
    }

    public function testRespectsVerbosityGating(): void
    {
        $output = new NullOutput();
        $output->setVerbosity(Verbosity::QUIET);
        $output->writeLine('hidden');
        $output->writeLine('also-hidden', Verbosity::VERBOSE);

        static::assertSame([], $output->lines());
    }

    public function testWriteAndWriteLineBothCapture(): void
    {
        $output = new NullOutput();
        $output->write('partial');
        $output->writeLine('finished');

        static::assertCount(2, $output->lines());
        static::assertSame('partial', $output->lines()[0][0]);
        static::assertSame('finished', $output->lines()[1][0]);
    }

    public function testVerbosityRoundtrip(): void
    {
        $output = new NullOutput();
        static::assertSame(Verbosity::NORMAL, $output->getVerbosity());

        $output->setVerbosity(Verbosity::DEBUG);
        static::assertSame(Verbosity::DEBUG, $output->getVerbosity());
    }
}
