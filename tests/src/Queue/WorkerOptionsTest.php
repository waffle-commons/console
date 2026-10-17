<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Queue;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Console\Exception\InvalidArgumentException;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Queue\WorkerOptions;
use Waffle\Commons\Contracts\Queue\Envelope;
use WaffleTests\Commons\Console\AbstractTestCase;
use WaffleTests\Commons\Console\Helper\Queue\Ping;

#[CoversClass(WorkerOptions::class)]
final class WorkerOptionsTest extends AbstractTestCase
{
    public function testAnOptionLeftOutKeepsItsDefault(): void
    {
        $options = WorkerOptions::fromInput(new ArgvInput([]));

        static::assertSame('default', $options->queue);
        static::assertSame(1000, $options->maxMessages);
        static::assertSame(128, $options->memoryLimitMb);
        static::assertSame(134_217_728, $options->memoryLimitBytes());
        static::assertSame(3, $options->maxAttempts);
        static::assertSame(5, $options->backoffSeconds);
        static::assertSame(1000, $options->waitMs);
    }

    public function testEveryOptionIsRead(): void
    {
        $options = WorkerOptions::fromInput(new ArgvInput([
            '--queue=mail',
            '--max-messages=50',
            '--memory-limit=256',
            '--max-attempts=7',
            '--backoff=0',
            '--wait=2000',
        ]));

        static::assertSame('mail', $options->queue);
        static::assertSame(50, $options->maxMessages);
        static::assertSame(256, $options->memoryLimitMb);
        static::assertSame(7, $options->maxAttempts);
        static::assertSame(0, $options->backoffSeconds);
        static::assertSame(2000, $options->waitMs);
    }

    public function testTheLastAllowedDeliveryIsTheOneThatExhaustsTheAttempts(): void
    {
        $options = new WorkerOptions(maxAttempts: 3);

        static::assertFalse($options->exhausts($this->envelope(attempts: 0)));
        static::assertFalse($options->exhausts($this->envelope(attempts: 1)));
        static::assertTrue($options->exhausts($this->envelope(attempts: 2)));
        // A message reclaimed after crashes may have failed more often than allowed.
        static::assertTrue($options->exhausts($this->envelope(attempts: 9)));
    }

    public function testTheBackOffDoublesWithEachFailedAttemptUpToAnHour(): void
    {
        $options = new WorkerOptions(backoffSeconds: 5);

        static::assertSame(5, $options->retryDelay($this->envelope(attempts: 0)));
        static::assertSame(10, $options->retryDelay($this->envelope(attempts: 1)));
        static::assertSame(20, $options->retryDelay($this->envelope(attempts: 2)));
        static::assertSame(2560, $options->retryDelay($this->envelope(attempts: 9)));
        static::assertSame(3600, $options->retryDelay($this->envelope(attempts: 10)));
        static::assertSame(3600, $options->retryDelay($this->envelope(attempts: 1_000)));
        static::assertSame(0, new WorkerOptions(backoffSeconds: 0)->retryDelay($this->envelope(attempts: 4)));
    }

    public function testTheNextDeliveryIsDueOnceTheBackOffHasPassed(): void
    {
        $now = new DateTimeImmutable('2026-10-10T09:00:00+00:00');

        $at = new WorkerOptions(backoffSeconds: 5)->retryAt($this->envelope(attempts: 1), $now);

        static::assertSame('2026-10-10T09:00:10+00:00', $at->format(DATE_ATOM));
        static::assertSame('2026-10-10T09:00:00+00:00', $now->format(DATE_ATOM));
    }

    /**
     * @return iterable<string, array{array<string, int|string>, string, string}>
     */
    public static function outOfRange(): iterable
    {
        yield 'no queue' => [['queue' => ''], 'queue', '--queue must name a queue.'];
        yield 'no message' => [['maxMessages' => 0], 'max-messages', '--max-messages must be at least 1, 0 given.'];
        yield 'no memory' => [
            ['memoryLimitMb' => 0],
            'memory-limit',
            '--memory-limit must be between 1 and 1048576, 0 given.',
        ];
        yield 'more than a TiB' => [
            ['memoryLimitMb' => 1_048_577],
            'memory-limit',
            '--memory-limit must be between 1 and 1048576, 1048577 given.',
        ];
        yield 'no attempt' => [['maxAttempts' => 0], 'max-attempts', '--max-attempts must be at least 1, 0 given.'];
        yield 'a negative back-off' => [
            ['backoffSeconds' => -1],
            'backoff',
            '--backoff must be between 0 and 3600, -1 given.',
        ];
        yield 'no wait' => [['waitMs' => 0], 'wait', '--wait must be at least 1, 0 given.'];
    }

    /**
     * @param array<string, int|string> $arguments
     */
    #[DataProvider('outOfRange')]
    public function testAValueOutOfRangeIsRefusedWithTheOptionItCameFrom(
        array $arguments,
        string $option,
        string $message,
    ): void {
        try {
            new WorkerOptions(...$arguments);
            static::fail('The value should have been refused.');
        } catch (InvalidArgumentException $refused) {
            static::assertSame($option, $refused->getArgumentName());
            static::assertSame($message, $refused->getMessage());
        }
    }

    public function testANumberWithDecimalsIsNotAWholeNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--wait expects a whole number, "1.5" given.');

        WorkerOptions::fromInput(new ArgvInput(['--wait=1.5']));
    }

    private function envelope(int $attempts): Envelope
    {
        return new Envelope('id', new Ping(), 'default', new DateTimeImmutable(), $attempts);
    }
}
