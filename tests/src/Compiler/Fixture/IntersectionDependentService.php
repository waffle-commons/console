<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * Service whose constructor takes an INTERSECTION-typed parameter
 * (`GreeterInterface&FirstUnionInterface`). Intersection types are not autowirable,
 * so the compiler cannot resolve the parameter statically and the whole definition
 * becomes a passthrough delegated to the runtime container (AOT-01: the
 * intersection-typed / unresolvable-ctor-param rejection branch).
 */
final class IntersectionDependentService
{
    public function __construct(
        public GreeterInterface&FirstUnionInterface $both,
    ) {}
}
