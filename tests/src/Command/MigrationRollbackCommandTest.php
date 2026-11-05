<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Waffle\Commons\Console\Command\AbstractCommand;
use Waffle\Commons\Console\Command\MigrationRollbackCommand;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Data\Migration\MigrationRunnerInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;
use WaffleTests\Commons\Console\AbstractTestCase;

use function implode;

#[CoversClass(MigrationRollbackCommand::class)]
#[CoversClass(AbstractCommand::class)]
final class MigrationRollbackCommandTest extends AbstractTestCase
{
    public function testNameDescriptionHelpAndSynopsis(): void
    {
        $command = new MigrationRollbackCommand(
            static::createStub(MigrationRunnerInterface::class),
            static::createStub(ResettableInterface::class),
        );

        static::assertSame('db:rollback', $command->getName());
        static::assertNotEmpty($command->getDescription());
        static::assertStringContainsString('--steps', $command->getHelp());
        static::assertSame('db:rollback [--steps=1]', $command->getSynopsis());
    }

    public function testOneStepByDefaultAndNothingAppliedIsASuccess(): void
    {
        $runner = $this->createMock(MigrationRunnerInterface::class);
        $runner->expects($this->once())->method('rollback')->with(1)->willReturn([]);

        $pool = $this->createMock(ResettableInterface::class);
        $pool->expects($this->once())->method('reset');

        $output = new NullOutput();
        $exit = new MigrationRollbackCommand($runner, $pool)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertStringContainsString('nothing to roll back', self::rendered($output));
    }

    public function testRevertedVersionsAreReportedInRealTime(): void
    {
        $runner = $this->createMock(MigrationRunnerInterface::class);
        $runner
            ->expects($this->once())
            ->method('rollback')
            ->with(2)
            ->willReturnCallback(static function (int $steps, ?Closure $onReverted): array {
                $reverted = ['Version2026102102_AddEmail', 'Version2026102101_CreateUsers'];
                if ($onReverted !== null) {
                    foreach ($reverted as $version) {
                        $onReverted($version);
                    }
                }

                return $reverted;
            });

        $pool = $this->createMock(ResettableInterface::class);
        $pool->expects($this->once())->method('reset');

        $output = new NullOutput();
        $exit = new MigrationRollbackCommand($runner, $pool)->execute(new ArgvInput(['--steps=2']), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        $rendered = self::rendered($output);
        static::assertStringContainsString('  reverted  Version2026102102_AddEmail', $rendered);
        static::assertStringContainsString('  reverted  Version2026102101_CreateUsers', $rendered);
        static::assertStringContainsString('Done — 2 migration(s) reverted.', $rendered);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSteps(): iterable
    {
        yield 'zero' => ['--steps=0'];
        yield 'negative' => ['--steps=-1'];
        yield 'not a number' => ['--steps=two'];
        yield 'a fraction' => ['--steps=1.5'];
        yield 'no value' => ['--steps'];
    }

    #[DataProvider('invalidSteps')]
    public function testAnInvalidStepCountIsAUsageErrorAndRevertsNothing(string $option): void
    {
        $runner = $this->createMock(MigrationRunnerInterface::class);
        $runner->expects($this->never())->method('rollback');

        $pool = $this->createMock(ResettableInterface::class);
        $pool->expects($this->never())->method('reset');

        $output = new NullOutput();
        $exit = new MigrationRollbackCommand($runner, $pool)->execute(new ArgvInput([$option]), $output);

        static::assertSame(ExitCode::USAGE->value, $exit);
        static::assertSame(
            ['[db:rollback] --steps expects a whole number of at least 1, e.g. --steps=2.'],
            $output->errors(),
        );
    }

    public function testRunnerFailureIsAFailureExitAndThePoolStillResets(): void
    {
        $runner = $this->createMock(MigrationRunnerInterface::class);
        $runner->expects($this->once())->method('rollback')->willThrowException(new RuntimeException('no down script'));

        $pool = $this->createMock(ResettableInterface::class);
        $pool->expects($this->once())->method('reset');

        $output = new NullOutput();
        $exit = new MigrationRollbackCommand($runner, $pool)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::FAILURE->value, $exit);
        static::assertSame(['[db:rollback] no down script'], $output->errors());
    }

    private static function rendered(NullOutput $output): string
    {
        $lines = [];
        foreach ($output->lines() as [$line]) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
