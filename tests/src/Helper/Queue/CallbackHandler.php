<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper\Queue;

use Closure;
use Waffle\Commons\Contracts\Queue\Envelope;
use Waffle\Commons\Contracts\Queue\MessageHandlerInterface;
use Waffle\Commons\Contracts\Queue\MessageInterface;

/** A handler whose behaviour the test writes. */
final readonly class CallbackHandler implements MessageHandlerInterface
{
    /**
     * @param class-string<MessageInterface>          $class
     * @param Closure(MessageInterface, Envelope): void $callback
     */
    public function __construct(
        private string $class,
        private Closure $callback,
    ) {}

    #[\Override]
    public function handles(): string
    {
        return $this->class;
    }

    #[\Override]
    public function handle(MessageInterface $message, Envelope $envelope): void
    {
        ($this->callback)($message, $envelope);
    }
}
