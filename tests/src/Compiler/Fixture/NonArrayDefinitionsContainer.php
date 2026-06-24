<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

use Waffle\Commons\Contracts\Container\ContainerInterface;

/**
 * Container double that DOES expose a `definitions` property, but whose value is
 * not an array. The compiler must reject it with a `CompilerException` — driving
 * the "definitions property is not an array" guard in
 * `ContainerCompiler::readDefinitions()`.
 */
final class NonArrayDefinitionsContainer implements ContainerInterface
{
    private string $definitions = 'not-an-array';

    #[\Override]
    public function get(string $id): mixed
    {
        return $id === '__definitions__' ? $this->definitions : null;
    }

    #[\Override]
    public function has(string $id): bool
    {
        return false;
    }

    #[\Override]
    public function set(string $id, object|callable|string $concrete): void {}

    #[\Override]
    public function reset(): void {}
}
