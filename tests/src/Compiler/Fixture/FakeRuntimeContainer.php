<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

use Closure;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use RuntimeException;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;

/**
 * Test stand-in for `Waffle\Commons\Container\Container` — the concrete container
 * is NOT in console's vendor (contracts-only perimeter), so the snapshot test
 * wires this double instead. It mirrors the real container's surface the compiler
 * relies on: a private `definitions` map (read by reflection), reflection-based
 * autowiring, instance memoisation, and a `reset()` cascade. The generated
 * `CompiledContainer` composes THIS class as its runtime.
 *
 * AOT-04: the autowire path mirrors `Waffle\Commons\Container\Autowire::
 * resolveDependencies()` BYTE FOR BYTE — including the branches that THROW
 * (unresolvable builtin without a default, a union with no registered member and
 * no default, an unregistered non-nullable class dependency). The previous double
 * silently *skipped* those parameters, which made the snapshot test blind to the
 * AOT-01/AOT-05 divergences: a compiled container that diverged from Autowire's
 * null/default/throw semantics still produced an equal graph because the runtime
 * double never reproduced Autowire's behaviour. Faithfully reproducing it means
 * the graph-identity assertion now genuinely fails when the compiler diverges.
 */
final class FakeRuntimeContainer implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, string|Closure|object|callable> */
    private array $definitions = [];

    /** @var array<string, true> */
    private array $resolving = [];

    #[\Override]
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!$this->has($id)) {
            throw new RuntimeException(sprintf('Service "%s" not found.', $id));
        }

        if (array_key_exists($id, $this->resolving)) {
            throw new RuntimeException(sprintf('Circular dependency on "%s".', $id));
        }

        $this->resolving[$id] = true;
        try {
            $instance = $this->build($id);
            $this->instances[$id] = $instance;
        } finally {
            unset($this->resolving[$id]);
        }

        return $instance;
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
        if (is_object($concrete) && !$concrete instanceof Closure) {
            $this->instances[$id] = $concrete;
        }
    }

    #[\Override]
    public function reset(): void
    {
        foreach ($this->instances as $service) {
            if (!$service instanceof ResettableInterface) {
                continue;
            }

            $service->reset();
        }
    }

    private function build(string $id): mixed
    {
        $concrete = $this->definitions[$id] ?? $id;

        if ($concrete instanceof Closure) {
            return $concrete($this);
        }
        if (is_object($concrete)) {
            return $concrete;
        }
        if (is_string($concrete) && class_exists($concrete)) {
            return $this->autowire($concrete);
        }

        throw new RuntimeException(sprintf('Service "%s" not found.', $id));
    }

    private function autowire(string $class): mixed
    {
        $reflector = new ReflectionClass($class);
        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return $reflector->newInstance();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            // Skip variadics — the container cannot collect variadic service lists.
            if ($parameter->isVariadic()) {
                continue;
            }

            $type = $parameter->getType();

            // Intersection types (A&B) are not autowirable.
            if ($type instanceof ReflectionIntersectionType) {
                throw new RuntimeException(sprintf(
                    'Parameter "%s" uses an intersection type which cannot be autowired.',
                    $parameter->getName(),
                ));
            }

            if ($type instanceof ReflectionUnionType) {
                $dependencies[] = $this->resolveUnion($type, $parameter);
                continue;
            }

            // No type or builtin primitive — default value, else throw.
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                throw new RuntimeException(sprintf('Cannot resolve primitive parameter "%s".', $parameter->getName()));
            }

            // Named class/interface dependency.
            $name = $type->getName();
            if ($this->has($name)) {
                $dependencies[] = $this->get($name);
                continue;
            }
            if ($parameter->allowsNull()) {
                $dependencies[] = null;
                continue;
            }

            throw new RuntimeException(sprintf(
                'Dependency "%s" for parameter "%s" could not be resolved: not registered in the container.',
                $name,
                $parameter->getName(),
            ));
        }

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Mirrors `Autowire`'s union handling: the first REGISTERED non-builtin member
     * wins (left-to-right); when none is registered the parameter default is used,
     * else resolution throws.
     */
    private function resolveUnion(ReflectionUnionType $type, ReflectionParameter $parameter): mixed
    {
        foreach ($type->getTypes() as $unionType) {
            if (!$unionType instanceof ReflectionNamedType || $unionType->isBuiltin()) {
                continue;
            }
            if (!$this->has($unionType->getName())) {
                continue;
            }

            return $this->get($unionType->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new RuntimeException(sprintf(
            'Parameter "%s" with union type could not be resolved: no candidate type is registered or instantiable.',
            $parameter->getName(),
        ));
    }
}
