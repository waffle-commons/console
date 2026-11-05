<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Waffle\Commons\Console\Command\AbstractCommand;
use Waffle\Commons\Console\Command\MigrationStatusCommand;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Data\Migration\Enum\MigrationStatus;
use Waffle\Commons\Contracts\Data\Migration\MigrationRunnerInterface;
use Waffle\Commons\Contracts\Data\Migration\MigrationState;
use Waffle\Commons\Contracts\Service\ResettableInterface;
use WaffleTests\Commons\Console\AbstractTestCase;

use function array_column;

#[CoversClass(MigrationStatusCommand::class)]
#[CoversClass(AbstractCommand::class)]
final class MigrationStatusCommandTest extends AbstractTestCase
{
    public function testNameDescriptionHelpAndSynopsis(): void
    {
        $command = new MigrationStatusCommand(
            static::createStub(MigrationRunnerInterface::class),
            static::createStub(ResettableInterface::class),
        );

        static::assertSame('db:status', $command->getName());
        static::assertNotEmpty($command->getDescription());
        static::assertStringContainsString('db:rollback', $command->getHelp());
        static::assertSame('db:status', $command->getSynopsis());
    }

    public function testEveryVersionIsListedInAnAlignedTableWithASummary(): void
    {
        $runner = $this->createMock(MigrationRunnerInterface::class);
        $runner
            ->expects($this->once())
            ->method('status')
            ->willReturn([
                new MigrationState('Version2026060101_Legacy', MigrationStatus::Missing, false, '2026-06-01 10:00:00'),
                new MigrationState(
                    'Version2026102101_CreateUsers',
                    MigrationStatus::Applied,
                    true,
                    '2026-10-21 05:00:00',
                ),
                new MigrationState('Version2026102102_AddEmail', MigrationStatus::Pending, true),
            ]);

        $pool = $this->createMock(ResettableInterface::class);
        $pool->expects($this->once())->method('reset');

        $output = new NullOutput();
        $exit = new MigrationStatusCommand($runner, $pool)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertSame(
            [
                'STATUS   VERSION                        APPLIED AT           DOWN',
                'missing  Version2026060101_Legacy       2026-06-01 10:00:00  no',
                'applied  Version2026102101_CreateUsers  2026-10-21 05:00:00  yes',
                'pending  Version2026102102_AddEmail     -                    yes',
                '3 migration(s): 1 applied, 1 pending, 1 missing.',
            ],
            array_column($output->lines(), 0),
        );
    }

    public function testNoMigrationAtAllIsReportedAsASuccess(): void
    {
        $runner = $this->createMock(MigrationRunnerInterface::class);
        $runner->expects($this->once())->method('status')->willReturn([]);

        $pool = $this->createMock(ResettableInterface::class);
        $pool->expects($this->once())->method('reset');

        $output = new NullOutput();
        $exit = new MigrationStatusCommand($runner, $pool)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertSame(
            ['No migration found, neither in the migrations directory nor in the migration log.'],
            array_column($output->lines(), 0),
        );
    }

    public function testAnUnreadableLogIsAFailureExitAndThePoolStillResets(): void
    {
        $runner = $this->createMock(MigrationRunnerInterface::class);
        $runner
            ->expects($this->once())
            ->method('status')
            ->willThrowException(new RuntimeException('Unable to read the migration log.'));

        $pool = $this->createMock(ResettableInterface::class);
        $pool->expects($this->once())->method('reset');

        $output = new NullOutput();
        $exit = new MigrationStatusCommand($runner, $pool)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::FAILURE->value, $exit);
        static::assertSame(['[db:status] Unable to read the migration log.'], $output->errors());
        static::assertSame([], $output->lines());
    }
}
