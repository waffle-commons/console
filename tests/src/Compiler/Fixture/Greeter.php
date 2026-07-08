<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

final class Greeter implements GreeterInterface
{
    public function __construct(
        public LeafService $leaf,
    ) {}

    #[\Override]
    public function greet(): string
    {
        return 'hello ' . $this->leaf->ping();
    }
}
