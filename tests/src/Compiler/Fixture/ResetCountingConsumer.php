<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * Never registered, so the runtime container autowires it by reflection: the
 * runtime-side path to an inlined {@see ResetCountingService} (CARRY-06).
 */
final class ResetCountingConsumer
{
    public function __construct(
        public ResetCountingService $counter,
    ) {}
}
