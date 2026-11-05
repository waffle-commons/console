<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use Closure;
use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Waffle\Commons\Console\Exception\ConsoleException;
use Waffle\Commons\Console\Exception\InvalidArgumentException;
use Waffle\Commons\Console\Queue\WorkerOptions;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\Enum\Verbosity;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;
use Waffle\Commons\Contracts\Queue\Envelope;
use Waffle\Commons\Contracts\Queue\Exception\QueueExceptionInterface;
use Waffle\Commons\Contracts\Queue\FailedMessageStoreInterface;
use Waffle\Commons\Contracts\Queue\MessageHandlerInterface;
use Waffle\Commons\Contracts\Queue\QueueConsumerInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanKind;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanStatus;
use Waffle\Commons\Contracts\Telemetry\NullTextMapPropagator;
use Waffle\Commons\Contracts\Telemetry\NullTracer;
use Waffle\Commons\Contracts\Telemetry\TextMapPropagatorInterface;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;

use function array_key_exists;
use function memory_get_usage;
use function sprintf;

/**
 * `waffle queue:work` — the queue worker ([QUEUE-02]).
 *
 * Takes the messages of one queue and hands each one to the handler of its
 * class. A handled message is acknowledged. A failed delivery is released for
 * another attempt after an exponential back-off; once `--max-attempts`
 * deliveries have failed, the message goes to the dead-letter store with its
 * envelope and the reason, and is acknowledged.
 *
 * The worker is a long-running process, so it resets the application container
 * after every message, as the HTTP worker resets it after every request. It
 * stops, with exit code 0:
 *
 *  - on SIGTERM or SIGINT, once the message in flight is settled (with `pcntl`;
 *    an idle worker stops within `--wait` milliseconds);
 *  - after `--max-messages` messages, or at `--memory-limit`, so that its
 *    supervisor starts a fresh process.
 *
 * A queue or dead-letter store that fails stops it with exit code 1: the
 * message in flight is delivered again once the queue reclaims it.
 *
 * Each message continues the trace of the code that dispatched it.
 */
final readonly class QueueWorkCommand extends AbstractCommand
{
    /**
     * @param list<MessageHandlerInterface> $handlers   One handler per message class. The classes they
     *                                                  handle are the ones the consumer may rebuild.
     * @param ResettableInterface           $resettable What is reset after every message: the
     *                                                  application container.
     */
    public function __construct(
        private QueueConsumerInterface $consumer,
        private FailedMessageStoreInterface $failed,
        private array $handlers,
        private ResettableInterface $resettable,
        private ClockInterface $clock,
        private LoggerInterface $logger = new NullLogger(),
        private TracerInterface $tracer = new NullTracer(),
        private TextMapPropagatorInterface $propagator = new NullTextMapPropagator(),
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'queue:work';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Handles the messages of a queue until it is stopped or recycled.';
    }

    #[\Override]
    public function getHelp(): string
    {
        return (
            "Takes the messages of one queue and hands each one to the handler of its\n"
            . "class. A failed delivery is retried after --backoff seconds, doubled at each\n"
            . "further failure; after --max-attempts failed deliveries the message goes to\n"
            . "the dead-letter store. The container is reset after every message.\n"
            . "The worker exits 0 on SIGTERM or SIGINT once the message in flight is\n"
            . "settled, and after --max-messages messages or at --memory-limit MB so that\n"
            . 'its supervisor restarts it. It exits 1 when the queue fails.'
        );
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return (
            'queue:work [--queue=default] [--max-messages=1000] [--memory-limit=128] '
            . '[--max-attempts=3] [--backoff=5] [--wait=1000]'
        );
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $options = WorkerOptions::fromInput($input);
            $handlers = $this->handlersByClass();
        } catch (InvalidArgumentException $invalid) {
            $output->writeError(sprintf('[queue:work] %s', $invalid->getMessage()));

            return ExitCode::USAGE->value;
        } catch (ConsoleException $misconfigured) {
            $output->writeError(sprintf('[queue:work] %s', $misconfigured->getMessage()));

            return ExitCode::CONFIG->value;
        }

        $output->writeLine(sprintf(
            'Working on queue "%s": stops on SIGTERM or SIGINT, after %d message(s) or at %d MB.',
            $options->queue,
            $options->maxMessages,
            $options->memoryLimitMb,
        ));
        $this->logger->info('queue:work started.', [
            'queue' => $options->queue,
            'max_messages' => $options->maxMessages,
            'memory_limit_mb' => $options->memoryLimitMb,
            'max_attempts' => $options->maxAttempts,
        ]);

        $signal = 0;
        $restore = $this->trapStopSignals($signal);
        try {
            return $this->work($options, $handlers, $output, $signal);
        } finally {
            $restore();
        }
    }

    /**
     * Takes and settles messages until a stop signal or a limit.
     *
     * @param array<string, MessageHandlerInterface> $handlers
     * @param int                                    $signal   Set by the signal handler: the stop signal received, 0
     *                                                         while none was.
     */
    private function work(WorkerOptions $options, array $handlers, OutputInterface $output, int &$signal): int
    {
        $settled = 0;
        try {
            while ($signal === 0) {
                $envelope = $this->consumer->receive($options->queue, $options->waitMs);
                if ($envelope !== null) {
                    $this->process($envelope, $handlers, $options, $output);
                    $settled++;
                }

                $limit = $this->limitReached($options, $settled);
                if ($limit !== null) {
                    return $this->stop($output, $limit, $settled);
                }
            }
        } catch (QueueExceptionInterface $failure) {
            $this->logger->error('queue:work stopped: the queue failed.', ['exception' => $failure]);
            $output->writeError(sprintf('[queue:work] The queue failed: %s', $failure->getMessage()));

            return ExitCode::FAILURE->value;
        }

        return $this->stop($output, $signal === SIGINT ? 'SIGINT' : 'SIGTERM', $settled);
    }

    /**
     * Handles one delivery and settles it, then resets the container.
     *
     * @param array<string, MessageHandlerInterface> $handlers
     *
     * @throws QueueExceptionInterface When the queue or the dead-letter store cannot settle it.
     */
    #[WorkerSafe(
        reason: 'one span per message from the injected tracer, ended in finally: its attributes and status go to that span only, and nothing survives the message',
    )]
    private function process(Envelope $envelope, array $handlers, WorkerOptions $options, OutputInterface $output): void
    {
        $span = $this->tracer->startSpan(
            'process ' . $envelope->queue,
            SpanKind::Consumer,
            $this->propagator->extract($envelope->traceContext),
        );
        $span->setAttribute('messaging.operation.type', 'process');
        $span->setAttribute('messaging.destination.name', $envelope->queue);
        $span->setAttribute('messaging.message.id', $envelope->id);

        try {
            $failure = $this->handle($envelope, $handlers);
            if ($failure === null) {
                $this->consumer->acknowledge($envelope);
                $output->writeLine(
                    sprintf('  handled  %s %s', $envelope->id, $envelope->message::class),
                    Verbosity::VERBOSE,
                );

                return;
            }

            $span->recordException($failure);
            $span->setStatus(SpanStatus::Error);
            $this->settleFailure($envelope, $failure, $options, $output);
        } finally {
            $span->end();
            $this->resettable->reset();
        }
    }

    /**
     * Hands the message to the handler of its class. A failure is returned
     * rather than thrown: whatever its class, it fails this delivery only, and
     * never stops the worker.
     *
     * @param array<string, MessageHandlerInterface> $handlers
     */
    private function handle(Envelope $envelope, array $handlers): ?Throwable
    {
        $class = $envelope->message::class;
        try {
            $handler = $handlers[$class] ?? throw new ConsoleException(sprintf(
                'No handler is registered for %s.',
                $class,
            ));
            $handler->handle($envelope->message, $envelope);
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    /**
     * Releases a failed delivery for another attempt, or dead-letters the
     * message once its attempts are spent.
     *
     * @throws QueueExceptionInterface When the queue or the dead-letter store cannot take it.
     */
    private function settleFailure(
        Envelope $envelope,
        Throwable $failure,
        WorkerOptions $options,
        OutputInterface $output,
    ): void {
        $reason = sprintf('%s: %s', $failure::class, $failure->getMessage());
        $context = [
            'id' => $envelope->id,
            'message' => $envelope->message::class,
            'queue' => $envelope->queue,
            'attempt' => $envelope->attempts + 1,
            'exception' => $failure,
        ];

        if ($options->exhausts($envelope)) {
            // Kept as it would be delivered again, so that its attempts count every failed delivery,
            // this one included. Stored before it is acknowledged: if the store fails, the message
            // stays on the queue.
            $this->failed->store($envelope->withNextAttempt($this->clock->now()), $reason);
            $this->consumer->acknowledge($envelope);
            $this->logger->error('queue:work gave up on a message: it is in the dead-letter store.', $context);
            $output->writeLine(sprintf(
                '  failed   %s %s after %d attempt(s): %s',
                $envelope->id,
                $envelope->message::class,
                $envelope->attempts + 1,
                $reason,
            ));

            return;
        }

        $delay = $options->retryDelay($envelope);
        $this->consumer->release($envelope, $options->retryAt($envelope, $this->clock->now()));
        $this->logger->warning(
            'queue:work released a failed message for another attempt.',
            $context
            + [
                'retry_in_s' => $delay,
            ],
        );
        $output->writeLine(sprintf(
            '  retry    %s %s in %d s: %s',
            $envelope->id,
            $envelope->message::class,
            $delay,
            $reason,
        ));
    }

    /**
     * Why the worker should stop and be restarted, or null to go on.
     */
    private function limitReached(WorkerOptions $options, int $settled): ?string
    {
        if ($settled >= $options->maxMessages) {
            return sprintf('%d message(s) settled', $settled);
        }

        if (memory_get_usage(true) >= $options->memoryLimitBytes()) {
            return sprintf('memory limit of %d MB reached', $options->memoryLimitMb);
        }

        return null;
    }

    private function stop(OutputInterface $output, string $reason, int $settled): int
    {
        $this->logger->info('queue:work stopped.', ['reason' => $reason, 'settled' => $settled]);
        $output->writeLine(sprintf('Stopped (%s) after %d message(s).', $reason, $settled));

        return ExitCode::SUCCESS->value;
    }

    /**
     * The registered handlers by the message class they handle.
     *
     * @return array<string, MessageHandlerInterface>
     *
     * @throws ConsoleException When no handler is registered, or two handle the same class.
     */
    private function handlersByClass(): array
    {
        if ($this->handlers === []) {
            throw new ConsoleException('No message handler is registered: queue:work would have nothing to handle.');
        }

        $byClass = [];
        foreach ($this->handlers as $handler) {
            $class = $handler->handles();
            if (array_key_exists($class, $byClass)) {
                throw new ConsoleException(sprintf(
                    'Two message handlers handle %s: %s and %s.',
                    $class,
                    $byClass[$class]::class,
                    $handler::class,
                ));
            }

            $byClass[$class] = $handler;
        }

        return $byClass;
    }

    /**
     * Turns SIGTERM and SIGINT into a request to stop: `$signal` receives the
     * signal, and the worker stops once the message in flight is settled.
     *
     * @param int $signal The stop signal received, 0 while none was.
     *
     * @return Closure(): void Puts the default signal handling back.
     */
    private function trapStopSignals(int &$signal): Closure
    {
        if (!function_exists('pcntl_signal')) {
            $this->logger->warning(
                'pcntl is not loaded: a signal stops queue:work at once, and the message in flight is delivered '
                . 'again once the queue reclaims it.',
            );

            return static function (): void {};
        }

        $wasAsync = pcntl_async_signals(true);
        $stop = static function (int $received) use (&$signal): void {
            $signal = $received;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);

        return static function () use ($wasAsync): void {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_async_signals($wasAsync);
        };
    }
}
