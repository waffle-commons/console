<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper\Queue;

use Psr\Log\AbstractLogger;
use Stringable;

/** A logger that keeps each record's level and message. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string}> */
    public private(set) array $records = [];

    #[\Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message];
    }
}
