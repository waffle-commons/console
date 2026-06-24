<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

use Waffle\Commons\Contracts\Container\ContainerInterface;

/**
 * Container double whose `definitions` map mixes a normal string-keyed entry with
 * a numeric (int) key. The compiler must SKIP the non-string id while keeping the
 * string-keyed one — driving the `!is_string($id)` continue guard in
 * `ContainerCompiler::readDefinitions()`.
 */
final class IntKeyedDefinitionsContainer implements ContainerInterface
{
    /** @var array<array-key, mixed> */
    private array $definitions = [
        0 => LeafService::class,
        LeafService::class => LeafService::class,
    ];

    #[\Override]
    public function get(string $id): mixed
    {
        return $this->definitions[$id] ?? null;
    }

    #[\Override]
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->definitions) || class_exists($id);
    }

    #[\Override]
    public function set(string $id, object|callable|string $concrete): void
    {
        $this->definitions[$id] = $concrete;
    }

    #[\Override]
    public function reset(): void {}
}
