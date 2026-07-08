<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/** Root service: nested + repeated typed dependencies plus a primitive default. */
final class RootService
{
    public function __construct(
        public MidService $mid,
        public LeafService $leaf,
        public string $label = 'root',
    ) {}
}
