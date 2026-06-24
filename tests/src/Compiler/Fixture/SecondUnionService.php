<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * Second member of {@see UnionDependentService}'s union dependency — a concrete,
 * REGISTERED class. Because the first union member is an unbound interface, this
 * is the member Autowire (and the compiled container) must select (AOT-05).
 */
final class SecondUnionService
{
    public function value(): string
    {
        return 'second';
    }
}
