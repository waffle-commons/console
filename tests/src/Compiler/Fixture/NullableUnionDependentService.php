<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * Service with a NULLABLE union dependency (`FirstUnionInterface|ThirdUnionInterface|null`)
 * and NO default value. Both class members are unbound interfaces, so no candidate
 * is registered; because the parameter allows null, Autowire injects null and the
 * compiled wiring must emit the same `null` fallback — exercising the
 * "nullable union, no default, no registered candidate" emission branch.
 */
final class NullableUnionDependentService
{
    public function __construct(
        public FirstUnionInterface|ThirdUnionInterface|null $dependency,
    ) {}
}
