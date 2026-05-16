<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Console\Command\CacheClearCommand;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use WaffleTests\Commons\Console\AbstractTestCase;

#[CoversClass(CacheClearCommand::class)]
#[CoversClass(\Waffle\Commons\Console\Command\AbstractCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class CacheClearCommandTest extends AbstractTestCase
{
    public function testNameAndDescription(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $command = new CacheClearCommand($cache);

        static::assertSame('cache:clear', $command->getName());
        static::assertNotEmpty($command->getDescription());
        static::assertNotEmpty($command->getSynopsis());
    }

    public function testSuccessfulClearReturnsZero(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('clear')->willReturn(true);

        $exit = new CacheClearCommand($cache)->execute(new ArgvInput([]), new NullOutput());

        static::assertSame(ExitCode::SUCCESS->value, $exit);
    }

    public function testCacheReturnsFalseProducesFailureExit(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('clear')->willReturn(false);

        $output = new NullOutput();
        $exit = new CacheClearCommand($cache)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::FAILURE->value, $exit);
        static::assertNotEmpty($output->errors());
    }

    public function testCacheBackendThrowingIsCapturedAsFailure(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('clear')->willThrowException(new \RuntimeException('redis down'));

        $output = new NullOutput();
        $exit = new CacheClearCommand($cache)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::FAILURE->value, $exit);
        static::assertNotEmpty($output->errors());
        static::assertStringContainsString('redis down', $output->errors()[0]);
    }
}
