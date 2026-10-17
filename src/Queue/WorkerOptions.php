<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Queue;

use DateTimeImmutable;
use Waffle\Commons\Console\Exception\InvalidArgumentException;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Queue\Envelope;
use Waffle\Commons\Contracts\Queue\QueueDispatcherInterface;

use function filter_var;
use function is_int;
use function min;
use function sprintf;

/**
 * The limits `queue:work` runs under ([QUEUE-02]), read from its options.
 *
 * The limits are bounded on purpose: a worker always recycles after a number
 * of messages or at a memory ceiling, and always gives up on a message after a
 * number of failed deliveries.
 */
final class WorkerOptions
{
    public const int DEFAULT_MAX_MESSAGES = 1000;
    public const int DEFAULT_MEMORY_LIMIT_MB = 128;
    public const int DEFAULT_MAX_ATTEMPTS = 3;
    public const int DEFAULT_BACKOFF_SECONDS = 5;
    public const int DEFAULT_WAIT_MS = 1000;

    /** The longest a failed message waits for its next delivery, however many attempts failed. */
    public const int MAX_BACKOFF_SECONDS = 3600;

    /** The highest accepted memory ceiling (1 TiB), so that its byte count stays an integer. */
    public const int MAX_MEMORY_LIMIT_MB = 1_048_576;

    private const int BYTES_PER_MB = 1_048_576;

    /** Beyond this many doublings, any back-off of 1 s or more has already reached the cap. */
    private const int MAX_DOUBLINGS = 12;

    /**
     * @param string $queue          The queue to work on.
     * @param int    $maxMessages    How many messages to settle before the worker exits to be restarted.
     * @param int    $memoryLimitMb  The memory, in MB, at which the worker exits to be restarted.
     * @param int    $maxAttempts    How many deliveries of a message may fail before it is dead-lettered.
     * @param int    $backoffSeconds The wait before the second delivery; it doubles at each further failure.
     * @param int    $waitMs         How long each read waits for a message: an idle worker notices a
     *                               stop signal within this time.
     */
    public function __construct(
        public private(set) string $queue = QueueDispatcherInterface::DEFAULT_QUEUE {
            set(string $value) {
                if ($value === '') {
                    throw new InvalidArgumentException('--queue must name a queue.', 'queue');
                }

                $this->queue = $value;
            }
        },
        public private(set) int $maxMessages = self::DEFAULT_MAX_MESSAGES {
            set(int $value) {
                $this->maxMessages = self::within($value, 1, null, 'max-messages');
            }
        },
        public private(set) int $memoryLimitMb = self::DEFAULT_MEMORY_LIMIT_MB {
            set(int $value) {
                $this->memoryLimitMb = self::within($value, 1, self::MAX_MEMORY_LIMIT_MB, 'memory-limit');
            }
        },
        public private(set) int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS {
            set(int $value) {
                $this->maxAttempts = self::within($value, 1, null, 'max-attempts');
            }
        },
        public private(set) int $backoffSeconds = self::DEFAULT_BACKOFF_SECONDS {
            set(int $value) {
                $this->backoffSeconds = self::within($value, 0, self::MAX_BACKOFF_SECONDS, 'backoff');
            }
        },
        public private(set) int $waitMs = self::DEFAULT_WAIT_MS {
            set(int $value) {
                $this->waitMs = self::within($value, 1, null, 'wait');
            }
        },
    ) {}

    /**
     * Reads `--queue`, `--max-messages`, `--memory-limit`, `--max-attempts`,
     * `--backoff` and `--wait`; an option left out keeps its default.
     *
     * @throws InvalidArgumentException When an option has no value, is not a whole number, or is out of range.
     */
    public static function fromInput(InputInterface $input): self
    {
        return new self(
            queue: self::value($input, 'queue') ?? QueueDispatcherInterface::DEFAULT_QUEUE,
            maxMessages: self::integer($input, 'max-messages') ?? self::DEFAULT_MAX_MESSAGES,
            memoryLimitMb: self::integer($input, 'memory-limit') ?? self::DEFAULT_MEMORY_LIMIT_MB,
            maxAttempts: self::integer($input, 'max-attempts') ?? self::DEFAULT_MAX_ATTEMPTS,
            backoffSeconds: self::integer($input, 'backoff') ?? self::DEFAULT_BACKOFF_SECONDS,
            waitMs: self::integer($input, 'wait') ?? self::DEFAULT_WAIT_MS,
        );
    }

    /**
     * The memory ceiling in bytes, as `memory_get_usage()` counts them.
     */
    public function memoryLimitBytes(): int
    {
        return $this->memoryLimitMb * self::BYTES_PER_MB;
    }

    /**
     * Whether a failed delivery of `$envelope` was the last one allowed.
     */
    public function exhausts(Envelope $envelope): bool
    {
        return ($envelope->attempts + 1) >= $this->maxAttempts;
    }

    /**
     * How many seconds the message waits for its next delivery once the
     * delivery of `$envelope` failed: `--backoff`, doubled for each earlier
     * failure, at most an hour.
     */
    public function retryDelay(Envelope $envelope): int
    {
        return min(
            self::MAX_BACKOFF_SECONDS,
            $this->backoffSeconds * (1 << min($envelope->attempts, self::MAX_DOUBLINGS)),
        );
    }

    /**
     * When the message is delivered again, once the delivery of `$envelope`
     * failed at `$now`: {@see self::retryDelay()} seconds later.
     */
    public function retryAt(Envelope $envelope, DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->setTimestamp($now->getTimestamp() + $this->retryDelay($envelope));
    }

    /**
     * @throws InvalidArgumentException When `$value` is out of range.
     */
    private static function within(int $value, int $min, ?int $max, string $option): int
    {
        if ($value < $min || $max !== null && $value > $max) {
            throw new InvalidArgumentException(
                $max === null
                    ? sprintf('--%s must be at least %d, %d given.', $option, $min, $value)
                    : sprintf('--%s must be between %d and %d, %d given.', $option, $min, $max, $value),
                $option,
            );
        }

        return $value;
    }

    /**
     * The option's value, or null when the option was left out.
     *
     * @throws InvalidArgumentException When the option is given without a value.
     */
    private static function value(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);
        if ($value === null && $input->hasOption($option)) {
            throw new InvalidArgumentException(sprintf('--%s needs a value: --%s=<value>.', $option, $option), $option);
        }

        return $value;
    }

    /**
     * @throws InvalidArgumentException When the option has no value or is not a whole number.
     */
    private static function integer(InputInterface $input, string $option): ?int
    {
        $value = self::value($input, $option);
        if ($value === null) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new InvalidArgumentException(
                sprintf('--%s expects a whole number, "%s" given.', $option, $value),
                $option,
            );
        }

        return $integer;
    }
}
