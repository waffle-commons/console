<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * First member of {@see UnionDependentService}'s union dependency. It is an
 * interface that is NEVER bound, so the container reports it absent — Autowire
 * therefore skips it and falls through to the SECOND, registered member. The
 * compiled container must make the same `has()`-guarded selection (AOT-05).
 */
interface FirstUnionInterface
{
    public function first(): string;
}
