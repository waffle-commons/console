<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * Inlinable service whose constructor takes a union of ONLY builtin members
 * (`int|string`) with a declared default. No member is a class, so the compiler's
 * union resolver finds no candidate and falls back to the parameter default —
 * exercising the "union with no non-builtin member → default" branch of
 * `canResolveParameter()` and the empty-candidate default fallback of
 * `emitUnionArgument()`.
 */
final class BuiltinUnionDefaultService
{
    public function __construct(
        public int|string $value = 42,
    ) {}
}
