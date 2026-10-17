<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper\Queue;

use Waffle\Commons\Contracts\Queue\MessageInterface;

/** The message the test handlers handle. */
final readonly class Ping implements MessageInterface
{
    public function __construct(
        public string $text = 'ping',
    ) {}
}
