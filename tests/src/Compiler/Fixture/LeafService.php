<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/** Leaf service: no constructor dependencies. */
final class LeafService
{
    public function ping(): string
    {
        return 'leaf';
    }
}
