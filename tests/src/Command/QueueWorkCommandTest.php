<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use DateTimeImmutable;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Waffle\Commons\Console\Command\AbstractCommand;
use Waffle\Commons\Console\Command\QueueWorkCommand;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Console\Queue\WorkerOptions;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\Enum\Verbosity;
use Waffle\Commons\Contracts\Queue\Envelope;
use Waffle\Commons\Contracts\Queue\MessageHandlerInterface;
use Waffle\Commons\Contracts\Queue\MessageInterface;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanKind;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanStatus;
use Waffle\Commons\Contracts\Telemetry\SpanContextInterface;
use Waffle\Commons\Contracts\Telemetry\SpanInterface;
use Waffle\Commons\Contracts\Telemetry\TextMapPropagatorInterface;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;
use WaffleTests\Commons\Console\AbstractTestCase;
use WaffleTests\Commons\Console\Helper\Queue\CallbackHandler;
use WaffleTests\Commons\Console\Helper\Queue\CountingResettable;
use WaffleTests\Commons\Console\Helper\Queue\Ping;
use WaffleTests\Commons\Console\Helper\Queue\Pong;
use WaffleTests\Commons\Console\Helper\Queue\QueueFailure;
use WaffleTests\Commons\Console\Helper\Queue\RecordingFailedStore;
use WaffleTests\Commons\Console\Helper\Queue\RecordingLogger;
use WaffleTests\Commons\Console\Helper\Queue\ScriptedConsumer;

use function array_map;
use function array_values;
use function implode;
use function pcntl_signal_get_handler;
use function posix_getpid;
use function posix_kill;
use function sprintf;

#[CoversClass(QueueWorkCommand::class)]
#[CoversClass(AbstractCommand::class)]
#[CoversClass(WorkerOptions::class)]
final class QueueWorkCommandTest extends AbstractTestCase
{
    use PHPMock;

    private const string NOW = '2026-10-10T09:00:00+00:00';

    private CountingResettable $container;

    private RecordingFailedStore $failed;

    private NullOutput $output;

    public static function setUpBeforeClass(): void
    {
        // Defined before the command first calls it, so that the mock can take its place.
        self::defineFunctionMock('Waffle\\Commons\\Console\\Command', 'function_exists');
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->container = new CountingResettable();
        $this->failed = new RecordingFailedStore();
        $this->output = new NullOutput();
    }

    public function testNameDescriptionHelpAndSynopsis(): void
    {
        $command = $this->command(new ScriptedConsumer([]), []);

        static::assertSame('queue:work', $command->getName());
        static::assertNotEmpty($command->getDescription());
        static::assertStringContainsString('dead-letter store', $command->getHelp());
        static::assertStringStartsWith('queue:work [--queue=default]', $command->getSynopsis());
    }

    public function testEachMessageIsHandledAcknowledgedThenTheContainerIsReset(): void
    {
        $consumer = new ScriptedConsumer([$this->envelope('first'), $this->envelope('second')]);
        $handled = [];
        $handler = new CallbackHandler(Ping::class, function (MessageInterface $message, Envelope $envelope) use (
            &$handled,
        ): void {
            // The container of the previous message was reset before this one is handled.
            $handled[] = [$envelope->id, $this->container->resets];
        });
        $this->output->setVerbosity(Verbosity::VERBOSE);

        $exit = $this->work($this->command($consumer, [$handler]), '--queue=mail', '--max-messages=2', '--wait=250');

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertSame([['first', 0], ['second', 1]], $handled);
        static::assertSame(['first', 'second'], $this->ids($consumer->acknowledged));
        static::assertSame(2, $this->container->resets);
        static::assertSame([['mail', 250], ['mail', 250]], $consumer->reads);
        static::assertSame([], $consumer->released);
        static::assertStringContainsString('handled  first ' . Ping::class, $this->lines());
        static::assertStringContainsString('Stopped (2 message(s) settled) after 2 message(s).', $this->lines());
    }

    public function testAFailedDeliveryIsReleasedAfterABackOffThatDoublesWithEachAttempt(): void
    {
        $consumer = new ScriptedConsumer([
            $this->envelope('first', attempts: 0),
            $this->envelope('second', attempts: 2),
        ]);
        $handler = new CallbackHandler(Ping::class, static function (): void {
            throw new RuntimeException('The mail server is down.');
        });

        $exit = $this->work(
            $this->command($consumer, [$handler]),
            '--max-messages=2',
            '--max-attempts=5',
            '--backoff=10',
        );

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertSame([], $consumer->acknowledged);
        static::assertSame([], $this->failed->stored);
        // 10 s after the first failure, 40 s after the third.
        static::assertSame(
            [
                ['first',  '2026-10-10T09:00:10+00:00'],
                ['second', '2026-10-10T09:00:40+00:00'],
            ],
            array_map(static fn(array $release): array => [
                $release[0]->id,
                $release[1]->format(DATE_ATOM),
            ], $consumer->released),
        );
        static::assertSame(2, $this->container->resets);
        static::assertStringContainsString(
            'retry    second ' . Ping::class . ' in 40 s: RuntimeException: The mail server is down.',
            $this->lines(),
        );
    }

    public function testTheLastAllowedAttemptGoesToTheDeadLetterStoreWithItsEnvelope(): void
    {
        $envelope = $this->envelope('poison', attempts: 2, traceContext: ['traceparent' => '00-trace-span-01']);
        $consumer = new ScriptedConsumer([$envelope->withReceipt('1-0')]);
        $handler = new CallbackHandler(Ping::class, static function (): void {
            throw new RuntimeException('The invoice does not exist.');
        });
        $logger = new RecordingLogger();

        $exit = $this->work($this->command($consumer, [$handler], $logger), '--max-messages=1', '--max-attempts=3');

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        // Kept with its trace context, its attempts counting all three failed deliveries.
        static::assertSame(
            [[
                'poison',
                3,
                ['traceparent' => '00-trace-span-01'],
                null,
                'RuntimeException: The invoice does not exist.',
            ]],
            array_map(static fn(array $kept): array => [
                $kept[0]->id,
                $kept[0]->attempts,
                $kept[0]->traceContext,
                $kept[0]->receipt,
                $kept[1],
            ], $this->failed->stored),
        );
        // The delivery itself is acknowledged by its receipt.
        static::assertSame(
            ['1-0'],
            array_map(static fn(Envelope $acknowledged): ?string => $acknowledged->receipt, $consumer->acknowledged),
        );
        static::assertSame([], $consumer->released);
        static::assertContains(
            ['error', 'queue:work gave up on a message: it is in the dead-letter store.'],
            $logger->records,
        );
        static::assertStringContainsString(
            'failed   poison ' . Ping::class . ' after 3 attempt(s): RuntimeException: The invoice does not exist.',
            $this->lines(),
        );
    }

    public function testAMessageWithoutAHandlerFailsLikeAnyOtherDelivery(): void
    {
        $consumer = new ScriptedConsumer([new Envelope('stray', new Pong(), 'default', new DateTimeImmutable())]);

        $exit = $this->work($this->command($consumer, [$this->handler()]), '--max-messages=1', '--max-attempts=1');

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertCount(1, $this->failed->stored);
        static::assertSame(
            'Waffle\\Commons\\Console\\Exception\\ConsoleException: No handler is registered for ' . Pong::class . '.',
            $this->failed->stored[0][1],
        );
        static::assertSame(['stray'], $this->ids($consumer->acknowledged));
    }

    public function testTheWorkerStopsAtTheMemoryLimitToBeRestarted(): void
    {
        $consumer = new ScriptedConsumer([$this->envelope('first'), $this->envelope('second')]);

        // Any PHP process uses more than 1 MB: the limit is reached after the first message.
        $exit = $this->work($this->command($consumer, [$this->handler()]), '--memory-limit=1');

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertSame(['first'], $this->ids($consumer->acknowledged));
        static::assertSame(['second'], $this->ids($consumer->undelivered()));
        static::assertStringContainsString(
            'Stopped (memory limit of 1 MB reached) after 1 message(s).',
            $this->lines(),
        );
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function stopSignals(): iterable
    {
        yield 'SIGTERM' => [SIGTERM, 'SIGTERM'];
        yield 'SIGINT' => [SIGINT, 'SIGINT'];
    }

    #[DataProvider('stopSignals')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testAStopSignalLetsTheMessageInFlightFinishThenTheWorkerExitsZero(int $signal, string $name): void
    {
        $consumer = new ScriptedConsumer([$this->envelope('in-flight'), $this->envelope('next')]);
        $steps = [];
        $handler = new CallbackHandler(Ping::class, static function () use ($signal, &$steps): void {
            $steps[] = 'started';
            posix_kill(posix_getpid(), $signal);
            // The signal does not interrupt the handler: the worker only stops between messages.
            $steps[] = 'finished';
        });

        $exit = $this->work($this->command($consumer, [$handler]));

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertSame(['started', 'finished'], $steps);
        static::assertSame(['in-flight'], $this->ids($consumer->acknowledged));
        static::assertSame(['next'], $this->ids($consumer->undelivered()));
        static::assertSame(1, $this->container->resets);
        static::assertStringContainsString('Stopped (' . $name . ') after 1 message(s).', $this->lines());
        // The default handling is back: the signal no longer reaches the worker.
        static::assertSame(SIG_DFL, pcntl_signal_get_handler($signal));
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testAnIdleWorkerStopsOnSigtermAfterItsCurrentRead(): void
    {
        $consumer = new ScriptedConsumer([], static function (): ?Envelope {
            // The signal arrives while the worker waits for a message.
            posix_kill(posix_getpid(), SIGTERM);

            return null;
        });

        $exit = $this->work($this->command($consumer, [$this->handler()]));

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertCount(1, $consumer->reads);
        static::assertSame(0, $this->container->resets);
        static::assertStringContainsString('Stopped (SIGTERM) after 0 message(s).', $this->lines());
    }

    public function testWithoutPcntlTheWorkerSaysHowASignalWillStopIt(): void
    {
        $this
            ->getFunctionMock('Waffle\\Commons\\Console\\Command', 'function_exists')
            ->expects(static::once())
            ->with('pcntl_signal')
            ->willReturn(false);
        $consumer = new ScriptedConsumer([$this->envelope('first')]);
        $logger = new RecordingLogger();

        $exit = $this->work($this->command($consumer, [$this->handler()], $logger), '--max-messages=1');

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertSame(['first'], $this->ids($consumer->acknowledged));
        static::assertContains(
            [
                'warning',
                'pcntl is not loaded: a signal stops queue:work at once, and the message in flight is delivered '
                    . 'again once the queue reclaims it.',
            ],
            $logger->records,
        );
    }

    public function testAQueueThatFailsStopsTheWorkerWithExitCodeOne(): void
    {
        $consumer = new ScriptedConsumer([]);
        $logger = new RecordingLogger();

        $exit = $this->work($this->command($consumer, [$this->handler()], $logger));

        static::assertSame(ExitCode::FAILURE->value, $exit);
        static::assertSame(['[queue:work] The queue failed: No delivery is left.'], $this->output->errors());
        static::assertContains(['error', 'queue:work stopped: the queue failed.'], $logger->records);
    }

    public function testAnAcknowledgementThatFailsStopsTheWorkerOnceTheContainerIsReset(): void
    {
        $consumer = new ScriptedConsumer([$this->envelope(
            'first',
        )], acknowledgeFailure: new QueueFailure('Redis went away.'));

        $exit = $this->work($this->command($consumer, [$this->handler()]));

        static::assertSame(ExitCode::FAILURE->value, $exit);
        static::assertSame(1, $this->container->resets);
        static::assertSame(['[queue:work] The queue failed: Redis went away.'], $this->output->errors());
    }

    public function testADeadLetterStoreThatFailsLeavesTheMessageOnTheQueue(): void
    {
        $this->failed = new RecordingFailedStore(new QueueFailure('The store is read-only.'));
        $consumer = new ScriptedConsumer([$this->envelope('poison')]);
        $handler = new CallbackHandler(Ping::class, static function (): void {
            throw new RuntimeException('boom');
        });

        $exit = $this->work($this->command($consumer, [$handler]), '--max-attempts=1');

        static::assertSame(ExitCode::FAILURE->value, $exit);
        // Never acknowledged: the queue delivers it again once it reclaims it.
        static::assertSame([], $consumer->acknowledged);
        static::assertSame(['[queue:work] The queue failed: The store is read-only.'], $this->output->errors());
    }

    public function testEachMessageContinuesTheTraceOfTheCodeThatDispatchedIt(): void
    {
        $carrier = ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'];
        $parent = static::createStub(SpanContextInterface::class);
        $propagator = $this->createMock(TextMapPropagatorInterface::class);
        $propagator->expects(static::exactly(2))->method('extract')->with($carrier)->willReturn($parent);

        $handled = $this->createMock(SpanInterface::class);
        $handled->expects(static::exactly(3))->method('setAttribute');
        $handled->expects(static::never())->method('setStatus');
        $handled->expects(static::once())->method('end');

        $failed = $this->createMock(SpanInterface::class);
        $failed->expects(static::exactly(3))->method('setAttribute');
        $failed->expects(static::once())->method('recordException');
        $failed->expects(static::once())->method('setStatus')->with(SpanStatus::Error);
        $failed->expects(static::once())->method('end');

        $tracer = $this->createMock(TracerInterface::class);
        $tracer
            ->expects(static::exactly(2))
            ->method('startSpan')
            ->with('process default', SpanKind::Consumer, $parent)
            ->willReturnOnConsecutiveCalls($handled, $failed);

        $consumer = new ScriptedConsumer([
            $this->envelope('first', traceContext: $carrier),
            $this->envelope('second', traceContext: $carrier),
        ]);
        $handler = new CallbackHandler(Ping::class, static function (
            MessageInterface $message,
            Envelope $envelope,
        ): void {
            if ($envelope->id === 'second') {
                throw new RuntimeException('boom');
            }
        });
        $command = new QueueWorkCommand(
            $consumer,
            $this->failed,
            [$handler],
            $this->container,
            $this->clock(),
            tracer: $tracer,
            propagator: $propagator,
        );

        static::assertSame(ExitCode::SUCCESS->value, $this->work($command, '--max-messages=2'));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'a count of zero' => [['--max-messages=0'], '--max-messages must be at least 1, 0 given.'];
        yield 'a count that is not a number' => [
            ['--max-attempts=three'],
            '--max-attempts expects a whole number, "three" given.',
        ];
        yield 'an option without its value' => [['--queue'], '--queue needs a value: --queue=<value>.'];
        yield 'an empty queue name' => [['--queue='], '--queue must name a queue.'];
        yield 'a back-off over an hour' => [['--backoff=3601'], '--backoff must be between 0 and 3600, 3601 given.'];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidOptions')]
    public function testAnInvalidOptionIsAUsageError(array $arguments, string $error): void
    {
        $consumer = new ScriptedConsumer([]);

        $exit = $this->work($this->command($consumer, [$this->handler()]), ...$arguments);

        static::assertSame(ExitCode::USAGE->value, $exit);
        static::assertSame(['[queue:work] ' . $error], $this->output->errors());
        static::assertSame([], $consumer->reads);
    }

    public function testAWorkerWithoutAHandlerIsAConfigurationError(): void
    {
        $exit = $this->work($this->command(new ScriptedConsumer([]), []));

        static::assertSame(ExitCode::CONFIG->value, $exit);
        static::assertSame(
            ['[queue:work] No message handler is registered: queue:work would have nothing to handle.'],
            $this->output->errors(),
        );
    }

    public function testTwoHandlersForOneMessageClassAreAConfigurationError(): void
    {
        $exit = $this->work($this->command(new ScriptedConsumer([]), [$this->handler(), $this->handler()]));

        static::assertSame(ExitCode::CONFIG->value, $exit);
        static::assertSame(
            [sprintf(
                '[queue:work] Two message handlers handle %s: %s and %s.',
                Ping::class,
                CallbackHandler::class,
                CallbackHandler::class,
            )],
            $this->output->errors(),
        );
    }

    /**
     * @param list<MessageHandlerInterface> $handlers
     */
    private function command(
        ScriptedConsumer $consumer,
        array $handlers,
        ?RecordingLogger $logger = null,
    ): QueueWorkCommand {
        return new QueueWorkCommand(
            $consumer,
            $this->failed,
            $handlers,
            $this->container,
            $this->clock(),
            $logger ?? new RecordingLogger(),
        );
    }

    private function work(QueueWorkCommand $command, string ...$arguments): int
    {
        // A memory ceiling no test process reaches, unless the test sets its own: the last one given wins.
        return $command->execute(new ArgvInput(['--memory-limit=4096', ...array_values($arguments)]), $this->output);
    }

    private function handler(): CallbackHandler
    {
        return new CallbackHandler(Ping::class, static function (): void {});
    }

    private function clock(): ClockInterface
    {
        $clock = static::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

        return $clock;
    }

    /**
     * @param array<string, string> $traceContext
     */
    private function envelope(string $id, int $attempts = 0, array $traceContext = []): Envelope
    {
        return new Envelope($id, new Ping(), 'default', new DateTimeImmutable(self::NOW), $attempts, $traceContext);
    }

    /**
     * @param list<Envelope> $envelopes
     *
     * @return list<string>
     */
    private function ids(array $envelopes): array
    {
        return array_map(static fn(Envelope $envelope): string => $envelope->id, $envelopes);
    }

    private function lines(): string
    {
        return implode("\n", array_map(static fn(array $line): string => $line[0], $this->output->lines()));
    }
}
