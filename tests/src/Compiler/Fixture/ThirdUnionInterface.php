<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * Second interface member of {@see NullableUnionDependentService}'s union. Like
 * {@see FirstUnionInterface} it is NEVER bound and is an interface (so
 * `class_exists()` is false), keeping the whole union unregistered so both
 * Autowire and the compiled wiring fall back to the nullable union's null value.
 */
interface ThirdUnionInterface
{
    public function third(): string;
}
