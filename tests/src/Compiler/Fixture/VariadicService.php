<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * Inlinable service whose constructor mixes a resolvable class dependency with a
 * trailing VARIADIC parameter. The container cannot collect variadic service
 * lists, so the compiler emits the leading positional argument and then stops at
 * the variadic — exercising the variadic-skip branches in both
 * `canResolveParameter()` and `emitConstruction()`/`emitArgument()`.
 *
 * @no-named-arguments
 */
final class VariadicService
{
    /** @var list<string> */
    public array $tags;

    public function __construct(
        public LeafService $leaf,
        string ...$tags,
    ) {
        $this->tags = array_values($tags);
    }
}
