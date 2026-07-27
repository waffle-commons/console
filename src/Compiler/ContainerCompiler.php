<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Compiler;

use Closure;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;
use Waffle\Commons\Console\Exception\CompilerException;
use Waffle\Commons\Contracts\Container\ContainerInterface;

/**
 * Ahead-of-Time service-container compiler (AOT-01).
 *
 * Given a fully-booted, locked runtime container, the compiler reads its service
 * `definitions` map and emits PHP source for a generated class implementing
 * `Waffle\Commons\Contracts\Container\CompiledContainerInterface`.
 *
 * ## Design
 *
 * The generated class **composes** the runtime
 * `Waffle\Commons\Container\Container` (holds it as a readonly property). It does
 * NOT re-declare any new cross-request mutable container state beyond its own
 * memo map, whose lifecycle exactly mirrors the runtime container's own
 * `$instances` (SEC-04): identity persists for the worker's lifetime, `reset()`
 * only scrubs internal state via `ResettableInterface` — it never evicts the memo.
 * An earlier revision cleared the compiled memo on every `reset()`, which forced a
 * fresh `new` for every inlined service each request (defeating the AOT fast
 * path's purpose) while the runtime container kept identity — a behavioural
 * discrepancy between interpreted and compiled modes that has been corrected.
 *
 *   - `has()` / `set()` delegate to the composed runtime container verbatim.
 *   - `reset()` resets the composed runtime container AND cascades any resettable
 *     services memoised by the compiled `get()` (so an inlined resettable service
 *     still participates in the per-request reset cascade — RFC-019).
 *   - `get()` uses **hardcoded constructor wiring** for *inlinable* definitions
 *     (class-string concretes whose constructor the compiler could fully resolve
 *     by reflection), bypassing runtime reflection. Every other definition kind —
 *     closures (lazy factories) and pre-registered objects — is a **passthrough**
 *     delegated to the composed runtime container, since their construction logic
 *     cannot be expressed as static source.
 *
 * The emitted graph is therefore identical to the runtime container's (verified
 * by the snapshot test): same concrete classes, same constructor wiring — only
 * the *resolution mechanism* changes (static calls instead of reflection).
 *
 * ## Hand-rolled source
 *
 * Source is assembled as strings (no `nikic/php-parser` / codegen dependency,
 * which would breach the console contracts-only perimeter). The reflection logic
 * mirrors `Waffle\Commons\Container\Autowire` so the compiled wiring matches what
 * the runtime container would have built.
 */
final class ContainerCompiler
{
    /** Default concrete runtime container the generated class composes. */
    public const string DEFAULT_RUNTIME_CONTAINER = 'Waffle\\Commons\\Container\\Container';

    /**
     * Compiles the runtime container to PHP source for a `CompiledContainer` class.
     *
     * @param ContainerInterface $container       A fully-booted, locked runtime container.
     * @param string             $namespace       Target namespace for the generated class.
     * @param string             $className       Short class name for the generated class.
     * @param string             $runtimeContainer FQCN of the concrete container the generated
     *                                            class composes (and delegates passthroughs to).
     * @return string The generated PHP source (including the opening `<?php` tag).
     * @throws CompilerException When the container exposes no inspectable definition map.
     */
    public function compile(
        ContainerInterface $container,
        string $namespace = 'Waffle\\Generated',
        string $className = 'CompiledContainer',
        string $runtimeContainer = self::DEFAULT_RUNTIME_CONTAINER,
    ): string {
        $definitions = $this->readDefinitions($container);

        /** @var array<string, class-string> $inlinable Service id => concrete class to wire statically. */
        $inlinable = [];
        foreach ($definitions as $id => $concrete) {
            $class = $this->inlinableClass($concrete);
            if ($class === null) {
                continue;
            }

            $inlinable[$id] = $class;
        }

        return $this->emit($namespace, $className, $inlinable, ltrim($runtimeContainer, '\\'));
    }

    /**
     * Reads the private `definitions` map from any container instance via
     * reflection — keeping this component dependent only on the container
     * **contract**, never the concrete `Waffle\Commons\Container` package.
     *
     * @return array<string, mixed> id => concrete (class-string|object|Closure|callable)
     * @throws CompilerException
     */
    private function readDefinitions(ContainerInterface $container): array
    {
        $reflection = new ReflectionObject($container);
        if (!$reflection->hasProperty('definitions')) {
            throw new CompilerException(sprintf(
                'Cannot compile container "%s": it does not expose an inspectable "definitions" map.',
                $reflection->getName(),
            ));
        }

        $value = $reflection->getProperty('definitions')->getValue($container);
        if (!is_array($value)) {
            throw new CompilerException('Container "definitions" property is not an array.');
        }

        $definitions = [];
        foreach ($value as $id => $concrete) {
            if (!is_string($id)) {
                continue;
            }

            $definitions[$id] = $concrete;
        }

        return $definitions;
    }

    /**
     * Returns the concrete class to inline for a definition, or null when the
     * definition is a passthrough. A definition is inlinable when its concrete is
     * a class-string for an instantiable class whose constructor the compiler can
     * fully resolve. Closures, pre-built objects, and non-instantiable/abstract
     * classes are passthroughs.
     *
     * @return class-string|null
     */
    private function inlinableClass(mixed $concrete): ?string
    {
        if (!is_string($concrete) || !class_exists($concrete)) {
            return null;
        }

        try {
            $reflector = new ReflectionClass($concrete);
        } catch (Throwable) {
            return null;
        }

        if (!$reflector->isInstantiable()) {
            return null;
        }

        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return $concrete;
        }

        // Every constructor parameter must be statically resolvable.
        foreach ($constructor->getParameters() as $parameter) {
            if (!$this->canResolveParameter($parameter)) {
                return null;
            }
        }

        return $concrete;
    }

    /**
     * Whether a constructor parameter can be expressed as a static argument —
     * mirrors {@see \Waffle\Commons\Container\Autowire::resolveDependencies()}.
     */
    private function canResolveParameter(ReflectionParameter $parameter): bool
    {
        if ($parameter->isVariadic()) {
            // Variadics are skipped (the container cannot collect variadic lists).
            return true;
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionIntersectionType) {
            return false;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof ReflectionNamedType && !$unionType->isBuiltin()) {
                    return true;
                }
            }

            return $parameter->isDefaultValueAvailable();
        }

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $parameter->isDefaultValueAvailable();
        }

        // Named class/interface dependency: resolved at runtime via $this->get().
        return true;
    }

    /**
     * Emits the full generated-class source.
     *
     * @param array<string, class-string> $inlinable id => concrete class
     */
    private function emit(string $namespace, string $className, array $inlinable, string $runtimeContainer): string
    {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = sprintf('namespace %s;', $namespace);
        $lines[] = '';
        $lines[] = '// Generated by Waffle\\Commons\\Console\\Compiler\\ContainerCompiler (AOT-01).';
        $lines[] = '// Do not edit by hand — regenerate with `waffle container:compile`.';
        $lines[] = sprintf(
            'final class %s implements \\Waffle\\Commons\\Contracts\\Container\\CompiledContainerInterface',
            $className,
        );
        $lines[] = '{';
        $lines[] = '    /** @var array<string, mixed> Worker-lifetime memo of compiled singletons — identity';
        $lines[] = '     * persists across requests, mirroring the runtime container; reset() clears internal';
        $lines[] = '     * state via ResettableInterface, it does not evict the memo (SEC-04). */';
        $lines[] = '    private array $instances = [];';
        $lines[] = '';
        $lines[] = '    public function __construct(';
        $lines[] = sprintf('        private readonly \\%s $runtime,', $runtimeContainer);
        $lines[] = '    ) {}';
        $lines[] = '';
        $lines = [...$lines, ...$this->emitGet($inlinable)];
        $lines[] = '';
        $lines = [...$lines, ...$this->emitHas()];
        $lines[] = '';
        $lines = [...$lines, ...$this->emitSet()];
        $lines[] = '';
        $lines = [...$lines, ...$this->emitReset()];
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, class-string> $inlinable id => concrete class
     * @return list<string>
     */
    private function emitGet(array $inlinable): array
    {
        $lines = [];
        $lines[] = '    #[\\Override]';
        $lines[] = '    public function get(string $id): mixed';
        $lines[] = '    {';
        $lines[] = '        if (\\array_key_exists($id, $this->instances)) {';
        $lines[] = '            return $this->instances[$id];';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        $instance = match ($id) {';
        foreach ($inlinable as $id => $concrete) {
            $lines[] = sprintf('            %s => %s,', $this->quote($id), $this->emitConstruction($concrete));
        }
        $lines[] = '            // Non-inlinable definitions (closures, pre-registered objects) and';
        $lines[] = '            // autowire-by-class fall through to the composed runtime container.';
        $lines[] = '            default => $this->runtime->get($id),';
        $lines[] = '        };';
        $lines[] = '';
        $lines[] = '        $this->instances[$id] = $instance;';
        $lines[] = '';
        $lines[] = '        return $instance;';
        $lines[] = '    }';

        return $lines;
    }

    /**
     * Emits the `new \FQCN(...)` expression for an inlinable service id.
     *
     * @param class-string $class
     */
    private function emitConstruction(string $class): string
    {
        $reflector = new ReflectionClass($class);
        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return sprintf('new \\%s()', $class);
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $expr = $this->emitArgument($parameter);
            if ($expr === null) {
                // Variadic / skipped parameter: stop emitting positional args.
                break;
            }
            $args[] = $expr;
        }

        return sprintf('new \\%s(%s)', $class, implode(', ', $args));
    }

    /**
     * Emits the PHP expression for a single constructor argument, mirroring
     * {@see \Waffle\Commons\Container\Autowire::resolveDependencies()} byte for
     * byte. Returns null for variadics.
     */
    private function emitArgument(ReflectionParameter $parameter): ?string
    {
        if ($parameter->isVariadic()) {
            return null;
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionUnionType) {
            return $this->emitUnionArgument($type, $parameter);
        }

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $this->emitDefault($parameter);
        }

        // Named class/interface dependency. Autowire resolves it via the container
        // only when `has()` reports the id (so an unregistered class-string still
        // resolves through autowiring, since the runtime `has()` also returns true
        // for any existing class). When the dependency is NOT resolvable, Autowire
        // returns null for a nullable parameter and otherwise throws — so the
        // compiled wiring mirrors both branches exactly instead of eagerly calling
        // get() (which would throw where Autowire returns null).
        $name = $type->getName();
        $resolved = sprintf('$this->get(%s)', $this->quote($name));

        if ($parameter->allowsNull()) {
            // Autowire returns null for an unregistered nullable dependency
            // regardless of whether a default value is also declared.
            return sprintf('$this->has(%s) ? %s : null', $this->quote($name), $resolved);
        }

        // Non-nullable: Autowire calls get() when registered and otherwise throws.
        // The compiled get() throws identically for an unknown id, so emitting the
        // bare resolution reproduces both the success and the failure path.
        return $resolved;
    }

    /**
     * Emits the PHP expression for a union-typed argument, mirroring Autowire:
     * the first *registered* non-builtin member wins, evaluated left-to-right via
     * `has()`; when none is registered Autowire uses the parameter default (or
     * throws when there is none). The emission is a `has()`-guarded ternary chain
     * so the compiled selection picks the same member the runtime would.
     */
    private function emitUnionArgument(ReflectionUnionType $type, ReflectionParameter $parameter): string
    {
        /** @var list<string> $candidates */
        $candidates = [];
        foreach ($type->getTypes() as $unionType) {
            if (!$unionType instanceof ReflectionNamedType || $unionType->isBuiltin()) {
                continue;
            }

            $candidates[] = $unionType->getName();
        }

        // Fallback when no candidate is registered: the parameter default if one
        // exists, else the first candidate's bare get() so the compiled wiring
        // throws exactly where Autowire's "no candidate registered" branch does.
        // Autowire's union resolver (Waffle\Commons\Container\Autowire::
        // resolveDependencies()) does NOT honour the parameter's nullability for a
        // union: with no registered candidate and no default it THROWS, even for a
        // nullable union — so the compiled wiring must not short-circuit to null
        // here (that would diverge from the runtime graph).
        if ($parameter->isDefaultValueAvailable()) {
            $fallback = $this->exportValue($parameter->getDefaultValue());
        } else {
            $fallback = $candidates === [] ? 'null' : sprintf('$this->get(%s)', $this->quote($candidates[0]));
        }

        // Build the right-to-left ternary chain so the FIRST registered member wins.
        $expr = $fallback;
        foreach (array_reverse($candidates) as $candidate) {
            $expr = sprintf(
                '$this->has(%s) ? $this->get(%s) : (%s)',
                $this->quote($candidate),
                $this->quote($candidate),
                $expr,
            );
        }

        return $expr;
    }

    /**
     * Emits the default-value literal for a primitive / unresolvable parameter.
     */
    private function emitDefault(ReflectionParameter $parameter): string
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $this->exportValue($parameter->getDefaultValue());
        }

        if ($parameter->allowsNull()) {
            return 'null';
        }

        // Should be unreachable: isInlinable() rejected this case already.
        return 'null';
    }

    /**
     * @return list<string>
     */
    private function emitHas(): array
    {
        return [
            '    #[\\Override]',
            '    public function has(string $id): bool',
            '    {',
            '        return $this->runtime->has($id);',
            '    }',
        ];
    }

    /**
     * @return list<string>
     */
    private function emitSet(): array
    {
        return [
            '    #[\\Override]',
            '    public function set(string $id, object|callable|string $concrete): void',
            '    {',
            '        $this->runtime->set($id, $concrete);',
            '    }',
        ];
    }

    /**
     * @return list<string>
     */
    private function emitReset(): array
    {
        return [
            '    public function reset(): void',
            '    {',
            '        foreach ($this->instances as $service) {',
            '            if ($service instanceof \\Waffle\\Commons\\Contracts\\Service\\ResettableInterface) {',
            '                $service->reset();',
            '            }',
            '        }',
            '        $this->runtime->reset();',
            '    }',
        ];
    }

    /**
     * Single-quotes a string literal for safe embedding in generated source.
     */
    private function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Exports a scalar/array default value to a PHP literal. Restricted to the
     * value kinds a constructor default can hold; objects are never defaults.
     */
    private function exportValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return var_export($value, true);
        }
        if (is_string($value)) {
            return $this->quote($value);
        }

        // Arrays and enum/const defaults: var_export round-trips scalars/arrays.
        return var_export($value, true);
    }
}
