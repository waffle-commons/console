<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * An interface that is deliberately NEVER bound in the container, so the runtime
 * `has()` reports it absent (`class_exists()` is false for an interface and it is
 * not in the definitions map). Autowire therefore resolves a nullable dependency
 * of this type to null — the AOT-01 case the compiler must mirror.
 */
interface OptionalDependencyInterface
{
    public function tag(): string;
}
