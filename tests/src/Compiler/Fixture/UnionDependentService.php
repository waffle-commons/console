<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * Service with a UNION-typed dependency whose FIRST member is an unbound interface
 * and whose SECOND member is a registered concrete. Autowire selects the first
 * REGISTERED member (the second one); the compiled container must emit a
 * `has()`-guarded selection that resolves to the same member, instead of
 * unconditionally resolving the first union member (AOT-05).
 */
final class UnionDependentService
{
    public function __construct(
        public FirstUnionInterface|SecondUnionService $dependency,
    ) {}
}
