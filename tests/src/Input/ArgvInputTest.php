<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Input;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Console\Input\ArgvInput;
use WaffleTests\Commons\Console\AbstractTestCase;

#[CoversClass(ArgvInput::class)]
final class ArgvInputTest extends AbstractTestCase
{
    public function testLongOptionWithValue(): void
    {
        $input = new ArgvInput(['--env=prod']);
        static::assertSame('prod', $input->getOption('env'));
        static::assertTrue($input->hasOption('env'));
    }

    public function testLongOptionAsFlagDefaultsToTrue(): void
    {
        $input = new ArgvInput(['--force']);
        static::assertTrue($input->hasOption('force'));
        static::assertNull($input->getOption('force'), 'Bare flag yields no string value');
    }

    public function testShortOptionIsFlag(): void
    {
        $input = new ArgvInput(['-v']);
        static::assertTrue($input->hasOption('v'));
    }

    public function testPositionalsAreBoundToNamedArguments(): void
    {
        $input = new ArgvInput(['production', 'extra']);
        $input->bindArgumentNames(['environment', 'extra']);

        static::assertSame('production', $input->getArgument('environment'));
        static::assertSame('extra', $input->getArgument('extra'));
    }

    public function testMissingArgumentReturnsDefault(): void
    {
        $input = new ArgvInput([]);
        $input->bindArgumentNames(['environment']);

        static::assertNull($input->getArgument('environment'));
        static::assertSame('dev', $input->getArgument('environment', 'dev'));
    }

    public function testMixedArgumentsAndOptionsRoundTrip(): void
    {
        $input = new ArgvInput(['--env=prod', 'redis', '-v']);
        $input->bindArgumentNames(['pool']);

        static::assertSame('prod', $input->getOption('env'));
        static::assertSame('redis', $input->getArgument('pool'));
        static::assertTrue($input->hasOption('v'));
    }

    public function testGetArgumentsReturnsBoundMap(): void
    {
        $input = new ArgvInput(['a', 'b']);
        $input->bindArgumentNames(['first', 'second']);

        static::assertSame(['first' => 'a', 'second' => 'b'], $input->getArguments());
    }

    public function testGetOptionsReturnsAllSeenOptions(): void
    {
        $input = new ArgvInput(['--env=prod', '--force', '-v']);

        $opts = $input->getOptions();
        static::assertSame('prod', $opts['env'] ?? null);
        static::assertTrue($opts['force'] ?? null);
        static::assertTrue($opts['v'] ?? null);
    }

    public function testGetRawArgumentsReflectsConstructorInput(): void
    {
        $argv = ['--env=prod', 'pool-name'];
        static::assertSame($argv, new ArgvInput($argv)->getRawArguments());
    }
}
