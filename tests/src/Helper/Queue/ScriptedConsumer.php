<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper\Queue;

use Closure;
use DateTimeImmutable;
use Waffle\Commons\Contracts\Queue\Envelope;
use Waffle\Commons\Contracts\Queue\QueueConsumerInterface;

use function array_shift;

/**
 * A consumer that delivers the envelopes it is given, in order, and records how
 * the worker settles them. Once they run out, a read calls `$whenEmpty`, or
 * fails: a test never waits for a message that cannot come.
 */
final class ScriptedConsumer implements QueueConsumerInterface
{
    /** @var list<array{string, int}> The queue and wait of each read. */
    public private(set) array $reads = [];

    /** @var list<Envelope> */
    public private(set) array $acknowledged = [];

    /** @var list<array{Envelope, DateTimeImmutable}> */
    public private(set) array $released = [];

    /**
     * @param list<Envelope>              $deliveries
     * @param (Closure(): ?Envelope)|null $whenEmpty
     */
    public function __construct(
        private array $deliveries,
        private ?Closure $whenEmpty = null,
        private ?QueueFailure $acknowledgeFailure = null,
    ) {}

    /** @return list<Envelope> The envelopes never delivered. */
    public function undelivered(): array
    {
        return $this->deliveries;
    }

    #[\Override]
    public function receive(string $queue, int $timeoutMs = 0): ?Envelope
    {
        $this->reads[] = [$queue, $timeoutMs];
        $next = array_shift($this->deliveries);
        if ($next !== null) {
            return $next;
        }

        if ($this->whenEmpty === null) {
            throw new QueueFailure('No delivery is left.');
        }

        return ($this->whenEmpty)();
    }

    #[\Override]
    public function acknowledge(Envelope $envelope): void
    {
        if ($this->acknowledgeFailure !== null) {
            throw $this->acknowledgeFailure;
        }

        $this->acknowledged[] = $envelope;
    }

    #[\Override]
    public function release(Envelope $envelope, DateTimeImmutable $availableAt): void
    {
        $this->released[] = [$envelope, $availableAt];
    }
}
