<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * Service whose union dependency mixes a class member with a builtin (`int`) and
 * declares a default value. No union member is registered, so Autowire (and the
 * compiled container) fall back to the declared default — exercising both the
 * builtin-member skip and the default-fallback branches of the union emission
 * (AOT-05 edge cases).
 */
final class UnionDefaultService
{
    public function __construct(
        public FirstUnionInterface|int $value = 7,
    ) {}
}
