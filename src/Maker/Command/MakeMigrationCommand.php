<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker\Command;

use Psr\Clock\ClockInterface;
use Waffle\Commons\Console\Maker\AbstractMakerCommand;
use Waffle\Commons\Console\Maker\TemplateRenderer;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

use function basename;
use function getcwd;
use function glob;
use function max;
use function mb_trim;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Console command to scaffold a versioned SQL migration and its down script ([OPS-03], RFC-020).
 *
 * `make:migration CreateOrdersTable` writes `Version<YYYYMMDD><NN>_CreateOrdersTable.sql` and
 * its `.down.sql` into the migrations directory: the date comes from the injected clock and NN
 * is the next number of that day not yet used there (01 to 99), so versions sort in the order
 * they were created. Both files hold only comments, and the runner refuses a script without a
 * statement: a file left unedited is never logged as applied or reverted.
 */
final readonly class MakeMigrationCommand extends AbstractMakerCommand
{
    private const string VERSION_PREFIX = 'Version';
    private const string DATE_FORMAT = 'Ymd';
    private const int MAX_PER_DAY = 99;

    /**
     * @param string $migrationsPath The directory `db:migrate` reads (`waffle.database.migrations_path`);
     *        a relative path resolves against the working directory, as the runner resolves it.
     */
    public function __construct(
        private ClockInterface $clock,
        private string $migrationsPath = 'migrations',
    ) {
        parent::__construct();
    }

    #[\Override]
    public function getName(): string
    {
        return 'make:migration';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Generates a versioned SQL migration and its down script.';
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return 'make:migration <Name> [--target=<migrations directory>]';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bindArgumentNames($input, ['name']);
        $name = $input->getArgument('name');

        if ($name === null || mb_trim($name) === '') {
            throw new \InvalidArgumentException('[ERROR] Migration name is required (e.g. CreateOrdersTable).');
        }
        $this->assertValidIdentifier($name, 'migration name');

        $directory = $this->migrationsDirectory($input);
        $version = $this->nextVersion($directory, $name);

        $renderer = new TemplateRenderer();
        $variables = ['VERSION' => $version];

        $this->writeTextFile(
            $directory . '/' . $version . '.sql',
            $renderer->render($this->loadStub('migration'), $variables),
            false,
            $output,
        );
        $this->writeTextFile(
            $directory . '/' . $version . '.down.sql',
            $renderer->render($this->loadStub('migration_down'), $variables),
            false,
            $output,
        );

        return ExitCode::SUCCESS->value;
    }

    private function migrationsDirectory(InputInterface $input): string
    {
        $directory = $input->getOption('target') ?? $this->migrationsPath;
        if (!str_starts_with($directory, '/')) {
            $cwd = getcwd();
            $directory = ($cwd === false ? '.' : $cwd) . '/' . $directory;
        }

        return rtrim($directory, '/');
    }

    /**
     * `Version<YYYYMMDD><NN>_<Name>`, NN being the day's next number not yet used in `$directory`.
     *
     * @throws \RuntimeException When the day already has a migration numbered 99.
     */
    private function nextVersion(string $directory, string $name): string
    {
        $prefix = self::VERSION_PREFIX . $this->clock->now()->format(self::DATE_FORMAT);
        $taken = glob($directory . '/' . $prefix . '[0-9][0-9]_*.sql');

        $last = 0;
        foreach ($taken === false ? [] : $taken as $path) {
            $last = max($last, (int) substr(basename($path), strlen($prefix), 2));
        }

        if ($last >= self::MAX_PER_DAY) {
            throw new \RuntimeException(sprintf(
                '[ERROR] %s already has a migration numbered %d: no version is left for that day.',
                $prefix,
                self::MAX_PER_DAY,
            ));
        }

        return sprintf('%s%02d_%s', $prefix, $last + 1, $name);
    }
}
