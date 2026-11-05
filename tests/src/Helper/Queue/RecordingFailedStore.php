<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper\Queue;

use Waffle\Commons\Contracts\Queue\Envelope;
use Waffle\Commons\Contracts\Queue\FailedMessageStoreInterface;

/** A dead-letter store that records what the worker gives up on. */
final class RecordingFailedStore implements FailedMessageStoreInterface
{
    /** @var list<array{Envelope, string}> */
    public private(set) array $stored = [];

    public function __construct(
        private ?QueueFailure $failure = null,
    ) {}

    #[\Override]
    public function store(Envelope $envelope, string $reason): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->stored[] = [$envelope, $reason];
    }

    #[\Override]
    public function list(int $limit = 100): array
    {
        return [];
    }

    #[\Override]
    public function remove(string $id): void {}
}
