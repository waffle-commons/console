<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Console\Audit\SensitiveComparison;
use Waffle\Commons\Console\Audit\SensitiveComparisonScanner;
use Waffle\Commons\Console\Command\SensitiveComparisonAuditCommand;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use WaffleTests\Commons\Console\AbstractTestCase;

#[CoversClass(SensitiveComparisonAuditCommand::class)]
#[CoversClass(SensitiveComparison::class)]
final class SensitiveComparisonAuditCommandTest extends AbstractTestCase
{
    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = APP_ROOT . '/var/compare-audit-cmd-' . bin2hex(random_bytes(4));
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0o755, true);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $files = glob($this->dir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testNameDescriptionSynopsis(): void
    {
        $command = new SensitiveComparisonAuditCommand();

        self::assertSame('security:compare-audit', $command->getName());
        self::assertNotEmpty($command->getDescription());
        self::assertStringContainsString('security:compare-audit', $command->getSynopsis());
    }

    public function testCleanDirectoryPasses(): void
    {
        file_put_contents($this->dir . '/Clean.php', "<?php\nif (hash_equals(\$a, \$b)) {\n}\n");
        $output = new NullOutput();

        $exit = new SensitiveComparisonAuditCommand()->execute(new ArgvInput([$this->dir]), $output);

        self::assertSame(ExitCode::SUCCESS->value, $exit);
        self::assertSame([], $output->errors());
    }

    public function testViolationFailsWithUsageExitAndError(): void
    {
        $path = $this->dir . '/Bad.php';
        file_put_contents($path, "<?php\n\$ok = \$expectedHmac === \$providedHmac;\n");
        $output = new NullOutput();

        $exit = new SensitiveComparisonAuditCommand()->execute(new ArgvInput([$this->dir]), $output);

        self::assertSame(ExitCode::USAGE->value, $exit);
        self::assertNotEmpty($output->errors());
        $rendered = implode("\n", $output->errors());
        self::assertStringContainsString('Bad.php', $rendered);
        self::assertStringContainsString('hash_equals', $rendered);
    }

    public function testMissingPathReturnsNoInput(): void
    {
        $output = new NullOutput();

        $exit = new SensitiveComparisonAuditCommand()->execute(new ArgvInput([
            $this->dir . '/does-not-exist',
        ]), $output);

        self::assertSame(ExitCode::NO_INPUT->value, $exit);
        self::assertNotEmpty($output->errors());
    }

    public function testDefaultPathIsUsedWhenNoPositionalArgument(): void
    {
        // Only a flag is supplied, so resolvePath() falls back to the default "src".
        // Run from a dir without a "src" subdir to make the outcome deterministic.
        $previous = getcwd();
        chdir($this->dir);

        try {
            $output = new NullOutput();
            $exit = new SensitiveComparisonAuditCommand()->execute(new ArgvInput(['--verbose']), $output);
        } finally {
            if ($previous !== false) {
                chdir($previous);
            }
        }

        self::assertSame(ExitCode::NO_INPUT->value, $exit);
        self::assertNotEmpty($output->errors());
    }

    public function testInjectedScannerIsUsed(): void
    {
        file_put_contents($this->dir . '/Whatever.php', "<?php\n\$x = 1;\n");
        $command = new SensitiveComparisonAuditCommand(new SensitiveComparisonScanner());

        $exit = $command->execute(new ArgvInput([$this->dir]), new NullOutput());

        self::assertSame(ExitCode::SUCCESS->value, $exit);
    }
}
