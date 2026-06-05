<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Console\Command\MemoryAuditCommand;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use WaffleTests\Commons\Console\AbstractTestCase;
use WaffleTests\Commons\Console\Helper\FakeAuditRunner;

#[CoversClass(MemoryAuditCommand::class)]
final class MemoryAuditCommandTest extends AbstractTestCase
{
    private string $root = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->root = APP_ROOT . '/var/igor-audit-test-' . bin2hex(random_bytes(4));
        if (!is_dir($this->root)) {
            mkdir($this->root, 0o755, true);
        }
        touch($this->root . '/igor.sh');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $script = $this->root . '/igor.sh';
        if (is_file($script)) {
            unlink($script);
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testNameDescriptionSynopsis(): void
    {
        $command = new MemoryAuditCommand(new FakeAuditRunner(), $this->root);

        static::assertSame('igor:audit', $command->getName());
        static::assertNotEmpty($command->getDescription());
        static::assertStringContainsString('igor:audit', $command->getSynopsis());
    }

    public function testMissingScriptReturnsNoInput(): void
    {
        $runner = new FakeAuditRunner();
        $command = new MemoryAuditCommand($runner, APP_ROOT . '/var/does-not-exist-' . bin2hex(random_bytes(4)));
        $output = new NullOutput();

        $exit = $command->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::NO_INPUT->value, $exit);
        static::assertNotEmpty($output->errors());
        static::assertNull($runner->lastScriptPath);
    }

    public function testSuccessfulAuditStreamsOutputAndPasses(): void
    {
        $runner = new FakeAuditRunner(output: [
            ['🔬 auditing', false],
            ['all clean',   false],
        ], exitCode: 0);
        $command = new MemoryAuditCommand($runner, $this->root);
        $output = new NullOutput();

        $exit = $command->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        $rendered = implode("\n", array_map(static fn(array $line): string => $line[0], $output->lines()));
        static::assertStringContainsString('all clean', $rendered);
        static::assertSame($this->root . '/igor.sh', $runner->lastScriptPath);
        static::assertSame($this->root, $runner->lastWorkingDirectory);
        static::assertSame([], $runner->lastArguments);
    }

    public function testFailedAuditReturnsFailureAndWritesError(): void
    {
        $runner = new FakeAuditRunner(output: [['dangerous state', true]], exitCode: 1);
        $command = new MemoryAuditCommand($runner, $this->root);
        $output = new NullOutput();

        $exit = $command->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::FAILURE->value, $exit);
        static::assertNotEmpty($output->errors());
    }

    public function testLocalAndSilentFlagsAreForwarded(): void
    {
        $runner = new FakeAuditRunner();
        $command = new MemoryAuditCommand($runner, $this->root);

        $command->execute(new ArgvInput(['--local', '-s']), new NullOutput());

        static::assertSame(['--local', '--silent'], $runner->lastArguments);
    }

    public function testLocalByDefaultForwardsLocalWithoutFlag(): void
    {
        $runner = new FakeAuditRunner();
        $command = new MemoryAuditCommand($runner, $this->root, localByDefault: true);

        $command->execute(new ArgvInput([]), new NullOutput());

        static::assertNotNull($runner->lastArguments);
        static::assertContains('--local', $runner->lastArguments);
    }
}
