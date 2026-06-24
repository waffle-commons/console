<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/** Mid-level service: a single typed class dependency. */
final class MidService
{
    public function __construct(
        public LeafService $leaf,
    ) {}
}
