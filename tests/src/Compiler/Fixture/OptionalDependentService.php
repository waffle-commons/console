<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * Service whose only dependency is an UNREGISTERED, nullable interface that also
 * declares a default value (`= null`). Autowire resolves it to null because the
 * container's `has()` reports the interface absent; the compiled container must do
 * the same instead of eagerly calling `get()` (which would throw) — the AOT-01
 * "nullable-with-default unregistered class dep" case.
 */
final class OptionalDependentService
{
    public function __construct(
        public ?OptionalDependencyInterface $optional = null,
    ) {}
}
