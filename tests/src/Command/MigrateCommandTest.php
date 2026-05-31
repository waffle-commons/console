<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use Closure;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Waffle\Commons\Console\Command\MigrateCommand;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Data\Migration\MigrationRunnerInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;
use WaffleTests\Commons\Console\AbstractTestCase;

#[CoversClass(MigrateCommand::class)]
#[CoversClass(\Waffle\Commons\Console\Command\AbstractCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class MigrateCommandTest extends AbstractTestCase
{
    public function testNameDescriptionAndHelp(): void
    {
        $command = new MigrateCommand(
            $this->createStub(MigrationRunnerInterface::class),
            $this->createStub(ResettableInterface::class),
        );

        static::assertSame('db:migrate', $command->getName());
        static::assertNotEmpty($command->getDescription());
        static::assertNotEmpty($command->getHelp());
        static::assertSame('db:migrate', $command->getSynopsis());
    }

    public function testUpToDateDatabaseReportsSuccessAndResetsPool(): void
    {
        $runner = $this->createMock(MigrationRunnerInterface::class);
        $runner->method('run')->willReturn([]);

        $pool = $this->createMock(ResettableInterface::class);
        $pool->expects($this->once())->method('reset');

        $output = new NullOutput();
        $exit = new MigrateCommand($runner, $pool)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertNotEmpty($output->lines());
    }

    public function testAppliedMigrationsAreReportedInRealTime(): void
    {
        $runner = $this->createMock(MigrationRunnerInterface::class);
        $runner
            ->method('run')
            ->willReturnCallback(static function (?Closure $onApplied): array {
                if ($onApplied !== null) {
                    $onApplied('Version2026053101_CreateUsersTable');
                }

                return ['Version2026053101_CreateUsersTable'];
            });

        $pool = $this->createMock(ResettableInterface::class);
        $pool->expects($this->once())->method('reset');

        $output = new NullOutput();
        $exit = new MigrateCommand($runner, $pool)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        $rendered = '';
        foreach ($output->lines() as $line) {
            $rendered .= $line[0] . "\n";
        }
        static::assertStringContainsString('Version2026053101_CreateUsersTable', $rendered);
    }

    public function testRunnerFailureIsCapturedAsFailureExitAndPoolStillResets(): void
    {
        $runner = $this->createMock(MigrationRunnerInterface::class);
        $runner->method('run')->willThrowException(new RuntimeException('migration boom'));

        $pool = $this->createMock(ResettableInterface::class);
        $pool->expects($this->once())->method('reset');

        $output = new NullOutput();
        $exit = new MigrateCommand($runner, $pool)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::FAILURE->value, $exit);
        static::assertNotEmpty($output->errors());
        static::assertStringContainsString('migration boom', $output->errors()[0]);
    }
}
