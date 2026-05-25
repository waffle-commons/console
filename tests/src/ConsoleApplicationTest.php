<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Console\ConsoleApplication;
use Waffle\Commons\Console\Exception\CommandNotFoundException;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\CommandInterface;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\Enum\Verbosity;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

#[CoversClass(ConsoleApplication::class)]
#[AllowMockObjectsWithoutExpectations]
final class ConsoleApplicationTest extends AbstractTestCase
{
    private function command(string $name, string $description = '', int $exit = 0): CommandInterface
    {
        return new class($name, $description, $exit) implements CommandInterface {
            public bool $invoked = false;
            public ?InputInterface $capturedInput = null;

            public function __construct(
                private string $name,
                private string $description,
                private int $exit,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return $this->description;
            }

            public function getHelp(): string
            {
                return '';
            }

            public function getSynopsis(): string
            {
                return $this->name;
            }

            public function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->invoked = true;
                $this->capturedInput = $input;
                return $this->exit;
            }
        };
    }

    public function testNameAndVersion(): void
    {
        $app = new ConsoleApplication('Waffle', '0.1.0', new NullOutput(), argv: []);
        static::assertSame('Waffle', $app->getName());
        static::assertSame('0.1.0', $app->getVersion());
    }

    public function testAddAndHasAndFind(): void
    {
        $app = new ConsoleApplication(output: new NullOutput(), argv: []);
        $command = $this->command('cache:clear', 'flush cache');
        $app->add($command);

        static::assertTrue($app->has('cache:clear'));
        static::assertSame($command, $app->find('cache:clear'));
    }

    public function testFindThrowsForUnknownCommand(): void
    {
        $app = new ConsoleApplication(output: new NullOutput(), argv: []);

        $this->expectException(CommandNotFoundException::class);
        $this->expectExceptionMessage('cache:clear');
        $app->find('cache:clear');
    }

    public function testAllReturnsRegistry(): void
    {
        $app = new ConsoleApplication(output: new NullOutput(), argv: []);
        $app->add($this->command('a'));
        $app->add($this->command('b'));

        static::assertCount(2, $app->all());
        static::assertArrayHasKey('a', $app->all());
        static::assertArrayHasKey('b', $app->all());
    }

    public function testRunWithNoArgvShowsUsageAndExitsUsage(): void
    {
        $output = new NullOutput();
        $app = new ConsoleApplication(output: $output, argv: ['bin/waffle']);

        $exit = $app->run();

        static::assertSame(ExitCode::USAGE->value, $exit);
        static::assertNotEmpty($output->lines());
    }

    public function testListCommandShowsUsageAndExitsSuccess(): void
    {
        $output = new NullOutput();
        $app = new ConsoleApplication(output: $output, argv: ['bin/waffle', 'list']);
        $app->add($this->command('cache:clear', 'flush'));

        $exit = $app->run();

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertNotEmpty($output->lines());
    }

    public function testRunDispatchesToFoundCommand(): void
    {
        $command = $this->command('greet', 'say hi', exit: 0);
        $app = new ConsoleApplication(output: new NullOutput(), argv: ['bin/waffle', 'greet']);
        $app->add($command);

        static::assertSame(ExitCode::SUCCESS->value, $app->run());
        static::assertTrue($command->invoked); // @phpstan-ignore-line
    }

    public function testUnknownCommandSurfacedAsFailureExitCode(): void
    {
        $output = new NullOutput();
        $app = new ConsoleApplication(output: $output, argv: ['bin/waffle', 'unknown']);

        static::assertSame(ExitCode::FAILURE->value, $app->run());
        static::assertNotEmpty($output->errors());
    }

    public function testCommandThrowingThrowableProducesFailureExit(): void
    {
        $exploding = new class implements CommandInterface {
            public function getName(): string
            {
                return 'boom';
            }

            public function getDescription(): string
            {
                return '';
            }

            public function getHelp(): string
            {
                return '';
            }

            public function getSynopsis(): string
            {
                return 'boom';
            }

            public function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new \LogicException('something broke');
            }
        };

        $output = new NullOutput();
        $app = new ConsoleApplication(output: $output, argv: ['bin/waffle', 'boom']);
        $app->add($exploding);

        static::assertSame(ExitCode::FAILURE->value, $app->run());
        static::assertNotEmpty($output->errors());
        static::assertStringContainsString('something broke', $output->errors()[0]);
    }

    public function testVerboseFlagSetsVerbosity(): void
    {
        $output = new NullOutput();
        $app = new ConsoleApplication(output: $output, argv: ['bin/waffle', 'noop', '-v']);
        $app->add($this->command('noop'));

        $app->run();
        static::assertSame(Verbosity::VERBOSE, $output->getVerbosity());
    }

    public function testVeryVerboseFlagSetsVerbosity(): void
    {
        $output = new NullOutput();
        $app = new ConsoleApplication(output: $output, argv: ['bin/waffle', 'noop', '-vv']);
        $app->add($this->command('noop'));

        $app->run();
        static::assertSame(Verbosity::VERY_VERBOSE, $output->getVerbosity());
    }

    public function testDebugFlagSetsVerbosity(): void
    {
        $output = new NullOutput();
        $app = new ConsoleApplication(output: $output, argv: ['bin/waffle', 'noop', '--debug']);
        $app->add($this->command('noop'));

        $app->run();
        static::assertSame(Verbosity::DEBUG, $output->getVerbosity());
    }

    public function testQuietFlagSetsVerbosity(): void
    {
        $output = new NullOutput();
        $app = new ConsoleApplication(output: $output, argv: ['bin/waffle', 'noop', '--quiet']);
        $app->add($this->command('noop'));

        $app->run();
        static::assertSame(Verbosity::QUIET, $output->getVerbosity());
    }
}
