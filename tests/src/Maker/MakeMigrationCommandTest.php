<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Maker;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Maker\AbstractMakerCommand;
use Waffle\Commons\Console\Maker\Command\MakeMigrationCommand;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

use function array_diff;
use function array_values;
use function chdir;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function is_dir;
use function mkdir;
use function realpath;
use function rmdir;
use function scandir;
use function sort;
use function uniqid;
use function unlink;

#[CoversClass(MakeMigrationCommand::class)]
#[CoversClass(AbstractMakerCommand::class)]
final class MakeMigrationCommandTest extends TestCase
{
    private const string TODAY = '2026-10-21 23:59:59';

    private string $tempDir = '';

    #[\Override]
    protected function setUp(): void
    {
        $real = realpath(__DIR__ . '/../../../var');
        $this->tempDir = ($real !== false ? $real : '') . '/tmp_test_migration_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testMetadata(): void
    {
        $command = new MakeMigrationCommand($this->clock());

        static::assertSame('make:migration', $command->getName());
        static::assertNotEmpty($command->getDescription());
        static::assertSame('', $command->getHelp());
        static::assertSame('make:migration <Name> [--target=<migrations directory>]', $command->getSynopsis());
    }

    public function testGeneratesTheUpAndDownScriptsOfTheDaysFirstVersion(): void
    {
        $output = new NullOutput();
        $exit = new MakeMigrationCommand($this->clock())->execute(new ArgvInput([
            'CreateOrdersTable',
            '--target=' . $this->tempDir . '/migrations',
        ]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertSame(
            ['Version2026102101_CreateOrdersTable.down.sql', 'Version2026102101_CreateOrdersTable.sql'],
            $this->filesIn($this->tempDir . '/migrations'),
        );

        $up = (string) file_get_contents($this->tempDir . '/migrations/Version2026102101_CreateOrdersTable.sql');
        static::assertStringStartsWith('-- Version2026102101_CreateOrdersTable (up): applied by', $up);
        static::assertStringContainsString('only comments is refused', $up);

        $down = (string) file_get_contents($this->tempDir . '/migrations/Version2026102101_CreateOrdersTable.down.sql');
        static::assertStringStartsWith(
            '-- Version2026102101_CreateOrdersTable (down): run by `bin/waffle db:rollback` to revert '
            . 'Version2026102101_CreateOrdersTable.sql.',
            $down,
        );
        static::assertCount(2, $output->lines());
    }

    public function testTheNextVersionOfTheDayFollowsTheHighestNumberOnDisk(): void
    {
        mkdir($this->tempDir . '/migrations');
        file_put_contents($this->tempDir . '/migrations/Version2026102107_AddIndex.sql', 'SELECT 1;');
        file_put_contents($this->tempDir . '/migrations/Version2026102003_Yesterday.sql', 'SELECT 1;');
        $command = new MakeMigrationCommand($this->clock());
        $input = new ArgvInput(['AddEmail', '--target=' . $this->tempDir . '/migrations']);

        $command->execute($input, new NullOutput());
        $command->execute($input, new NullOutput());

        static::assertSame(
            [
                'Version2026102003_Yesterday.sql',
                'Version2026102107_AddIndex.sql',
                'Version2026102108_AddEmail.down.sql',
                'Version2026102108_AddEmail.sql',
                'Version2026102109_AddEmail.down.sql',
                'Version2026102109_AddEmail.sql',
            ],
            $this->filesIn($this->tempDir . '/migrations'),
        );
    }

    public function testADayWithVersion99HasNoVersionLeft(): void
    {
        mkdir($this->tempDir . '/migrations');
        file_put_contents($this->tempDir . '/migrations/Version2026102199_Last.sql', 'SELECT 1;');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Version20261021 already has a migration numbered 99');

        new MakeMigrationCommand($this->clock())->execute(new ArgvInput([
            'OneTooMany',
            '--target=' . $this->tempDir . '/migrations',
        ]), new NullOutput());
    }

    public function testTheConfiguredRelativePathResolvesAgainstTheWorkingDirectory(): void
    {
        $cwd = getcwd();
        chdir($this->tempDir);
        try {
            new MakeMigrationCommand($this->clock(), 'db/migrations/')->execute(
                new ArgvInput(['CreateUsersTable']),
                new NullOutput(),
            );
        } finally {
            if ($cwd !== false) {
                chdir($cwd);
            }
        }

        static::assertFileExists($this->tempDir . '/db/migrations/Version2026102101_CreateUsersTable.sql');
        static::assertFileExists($this->tempDir . '/db/migrations/Version2026102101_CreateUsersTable.down.sql');
    }

    public function testAnInputBoundByItsCallerIsReadAsIs(): void
    {
        $input = static::createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('CreateTagsTable');
        $input->method('getOption')->willReturn($this->tempDir);

        $exit = new MakeMigrationCommand($this->clock())->execute($input, new NullOutput());

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertFileExists($this->tempDir . '/Version2026102101_CreateTagsTable.sql');
    }

    public function testAMissingNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[ERROR] Migration name is required');

        new MakeMigrationCommand($this->clock())->execute(new ArgvInput([
            '--target=' . $this->tempDir,
        ]), new NullOutput());
    }

    public function testANameThatIsNotAnIdentifierIsRefusedBeforeAnyWrite(): void
    {
        try {
            new MakeMigrationCommand($this->clock())->execute(new ArgvInput([
                '../escape',
                '--target=' . $this->tempDir,
            ]), new NullOutput());
            static::fail('Expected an InvalidArgumentException for a path in the migration name.');
        } catch (\InvalidArgumentException $invalid) {
            static::assertStringContainsString('Invalid migration name "../escape"', $invalid->getMessage());
        }

        static::assertSame([], $this->filesIn($this->tempDir));
    }

    public function testATextFileIsWrittenWithoutThePhpSyntaxCheck(): void
    {
        $command = new readonly class extends AbstractMakerCommand {
            #[\Override]
            public function getName(): string
            {
                return 'test:write-text-file';
            }

            #[\Override]
            public function getDescription(): string
            {
                return '';
            }

            #[\Override]
            public function execute(InputInterface $input, OutputInterface $output): int
            {
                return ExitCode::SUCCESS->value;
            }

            public function writeTextPublic(string $filepath, string $content, OutputInterface $output): void
            {
                $this->writeTextFile($filepath, $content, false, $output);
            }
        };

        // Not valid PHP: writeFile() would refuse it, a text file is written as given.
        $command->writeTextPublic($this->tempDir . '/notes.txt', '<?php class Broken {', new NullOutput());

        static::assertSame('<?php class Broken {', file_get_contents($this->tempDir . '/notes.txt'));
    }

    private function clock(): ClockInterface
    {
        $clock = static::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable(self::TODAY));

        return $clock;
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $directory): array
    {
        $entries = scandir($directory);
        $files = array_values(array_diff($entries === false ? [] : $entries, ['.', '..']));
        sort($files);

        return $files;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            unlink($path);

            return;
        }

        $entries = scandir($path);
        foreach (array_diff($entries === false ? [] : $entries, ['.', '..']) as $entry) {
            $this->removeDirectory($path . '/' . $entry);
        }
        rmdir($path);
    }
}
