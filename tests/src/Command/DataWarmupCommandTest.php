<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Waffle\Commons\Console\Command\DataWarmupCommand;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Data\Warmup\DataWarmerInterface;
use WaffleTests\Commons\Console\AbstractTestCase;

#[CoversClass(DataWarmupCommand::class)]
#[CoversClass(\Waffle\Commons\Console\Command\AbstractCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class DataWarmupCommandTest extends AbstractTestCase
{
    public function testNameDescriptionAndHelp(): void
    {
        $command = new DataWarmupCommand([]);

        static::assertSame('data:warmup', $command->getName());
        static::assertNotEmpty($command->getDescription());
        static::assertNotEmpty($command->getHelp());
        static::assertSame('data:warmup', $command->getSynopsis());
    }

    public function testNoRegisteredWarmerReportsNothingToWarm(): void
    {
        $output = new NullOutput();
        $exit = new DataWarmupCommand([])->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertStringContainsString('Nothing to warm', $this->render($output));
    }

    public function testArtifactsFromEveryWarmerAreReportedInRealTime(): void
    {
        $queries = $this->createMock(DataWarmerInterface::class);
        $queries->method('warmUp')->willReturn(['3 compiled SQR tree(s) → var/cache/data-warmup.php']);

        $routes = $this->createMock(DataWarmerInterface::class);
        $routes->method('warmUp')->willReturn(['routing tables → PSR-16 cache']);

        $output = new NullOutput();
        $exit = new DataWarmupCommand([$queries, $routes])->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        $rendered = $this->render($output);
        static::assertStringContainsString('3 compiled SQR tree(s)', $rendered);
        static::assertStringContainsString('routing tables', $rendered);
        static::assertStringContainsString('2 artifact group(s) warmed', $rendered);
    }

    public function testWarmerReturningNoArtifactStillReportsNothingToWarm(): void
    {
        $idle = $this->createMock(DataWarmerInterface::class);
        $idle->method('warmUp')->willReturn([]);

        $output = new NullOutput();
        $exit = new DataWarmupCommand([$idle])->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertStringContainsString('Nothing to warm', $this->render($output));
    }

    public function testWarmerFailureIsCapturedAsFailureExit(): void
    {
        $broken = $this->createMock(DataWarmerInterface::class);
        $broken->method('warmUp')->willThrowException(new RuntimeException('disk full'));

        $output = new NullOutput();
        $exit = new DataWarmupCommand([$broken])->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::FAILURE->value, $exit);
    }

    private function render(NullOutput $output): string
    {
        $rendered = '';
        foreach ($output->lines() as $line) {
            $rendered .= $line[0] . "\n";
        }

        return $rendered;
    }
}
