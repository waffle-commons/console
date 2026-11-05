<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Compiler;

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
 * memo map, which holds **only the inlined services** and is a fast path over the
 * runtime container: `get()` builds an inlined service through the runtime's
 * `memoise()`, so the runtime's `$instances` hold the same instance (CARRY-06).
 * Identity persists for the worker's lifetime and `reset()` never evicts the memo
 * (SEC-04). An earlier revision cleared
 * the compiled memo on every `reset()`, which forced a fresh `new` for every
 * inlined service each request (defeating the AOT fast path's purpose) while the
 * runtime container kept identity — a behavioural discrepancy between interpreted
 * and compiled modes that has been corrected. A second, residual divergence has
 * been corrected as well: `get()` used to memoise its passthrough (`default`) arm
 * too, landing closures/pre-registered objects in BOTH the compiled memo and the
 * runtime container's `$instances`, so a resettable passthrough reset TWICE per
 * request in AOT mode versus exactly once interpreted. The emitted `get()` now
 * guards on a `self::INLINED` membership map and delegates every non-inlined id
 * to the runtime container without memoising it.
 *
 *   - `has()` / `set()` delegate to the composed runtime container verbatim.
 *   - `reset()` delegates to the composed runtime container. Its cascade covers every
 *     instance, the inlined ones included since they are memoised there too, so
 *     each resettable service resets exactly once per request (RFC-019).
 *   - `get()` uses **hardcoded constructor wiring** for *inlinable* definitions
 *     (class-string concretes whose constructor the compiler could fully resolve
 *     by reflection), bypassing runtime reflection, and memoises the result through
 *     the runtime container's `memoise()`. Every other definition kind —
 *     closures (lazy factories) and pre-registered objects — is a **passthrough**
 *     delegated to the composed runtime container and never memoised locally,
 *     since their construction logic cannot be expressed as static source and the
 *     runtime memo must remain their single reset()-cascade owner. When NO
 *     definition is inlinable the memo is dead weight, so the generated class
 *     omits it entirely and `get()`/`reset()` are pure delegations.
 *
 * The emitted graph is therefore structurally identical to the runtime
 * container's — verified by the snapshot test as a deep-equal, FQCN-normalised
 * comparison, NOT instance identity: same concrete classes, same constructor
 * wiring — only the *resolution mechanism* changes (static calls instead of
 * reflection). Because the inlined instances are memoised in the runtime container,
 * a closure factory or runtime-autowired passthrough that resolves an INLINED id
 * gets the same instance as the compiled `get()`, whichever side resolved it
 * first. Beta-6 accepted one instance per memo here; CARRY-06 removed that. The
 * runtime container class must therefore provide `memoise(string $id, Closure
 * $build)`: when any definition is inlinable, the compiler refuses a class that
 * lacks it or that it cannot load.
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
     * @throws CompilerException When the container exposes no inspectable definition map, or when
     *                           a definition is inlinable and the runtime container class has no
     *                           `memoise()` method or cannot be loaded.
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

        $runtimeContainer = ltrim($runtimeContainer, '\\');
        // The compiled get() memoises what it inlines through the runtime's memoise(); a class that
        // cannot be loaded here cannot show it has one.
        if ($inlinable !== [] && !method_exists($runtimeContainer, 'memoise')) {
            throw new CompilerException(sprintf(
                'Cannot compile against the runtime container "%s": the compiled get() memoises the services it inlines through memoise(string $id, Closure $build), and this class does not have it or cannot be loaded.',
                $runtimeContainer,
            ));
        }

        return $this->emit($namespace, $className, $inlinable, $runtimeContainer);
    }

    /**
     * Reads the private `definitions` map from any container instance via
     * reflection — keeping this component dependent only on the container
     * **contract**, never the concrete `Waffle\Commons\Container` package.
     *
     * Only the definitions whose concrete is a class name are kept: closures and
     * pre-built objects are passthroughs, never inlined.
     *
     * @return array<string, string> id => concrete class name
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

        // The property is read untyped: it is kept in a one-element list and
        // narrowed in place, never bound on its own.
        $value = [$reflection->getProperty('definitions')->getValue($container)];
        if (!is_array($value[0])) {
            throw new CompilerException('Container "definitions" property is not an array.');
        }

        $definitions = [];
        foreach (array_keys($value[0]) as $id) {
            $concrete = [$value[0][$id] ?? null];
            if (is_string($id) && is_string($concrete[0])) {
                $definitions[$id] = $concrete[0];
            }
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
    private function inlinableClass(string $concrete): ?string
    {
        if (!class_exists($concrete)) {
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
        if ($inlinable !== []) {
            $lines[] = '    /** @var array<string, mixed> Fast path over the runtime container for the INLINED';
            $lines[] = '     * singletons ONLY: get() memoises them there through memoise(), so the runtime holds';
            $lines[] = '     * the same instances and its reset() covers them; passthroughs are never stored here.';
            $lines[] = '     * Identity persists across requests: nothing ever evicts this memo (SEC-04). */';
            $lines[] = '    private array $instances = [];';
            $lines[] = '';
        }
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
     * Emits the `INLINED` membership map (when non-empty) and the `get()` method.
     *
     * Passthroughs (closures, pre-registered objects, autowire-by-class) are
     * delegated to the composed runtime container BEFORE the memo check and are
     * never memoised locally: the runtime memo is their single owner, so a
     * resettable passthrough participates in the reset() cascade exactly once per
     * request (previously it was memoised on BOTH sides and reset twice). Inlined
     * ids are built through the runtime container's `memoise()`, so its memo holds
     * them too and every path shares one instance (CARRY-06). With zero inlinable
     * definitions the whole body degenerates to the delegation.
     *
     * @param array<string, class-string> $inlinable id => concrete class
     * @return list<string>
     */
    private function emitGet(array $inlinable): array
    {
        if ($inlinable === []) {
            return [
                '    #[\\Override]',
                '    public function get(string $id): mixed',
                '    {',
                '        // No definition was inlinable: every id (closures, pre-registered',
                '        // objects, autowire-by-class) delegates to the composed runtime',
                '        // container, which owns the only memo — nothing is memoised here.',
                '        return $this->runtime->get($id);',
                '    }',
            ];
        }

        $lines = [];
        $lines[] = '    /** @var array<string, true> Membership map of the ids get() inlines below. */';
        $lines[] = '    private const array INLINED = [';
        foreach (array_keys($inlinable) as $id) {
            $lines[] = sprintf('        %s => true,', $this->quote($id));
        }
        $lines[] = '    ];';
        $lines[] = '';
        $lines[] = '    #[\\Override]';
        $lines[] = '    public function get(string $id): mixed';
        $lines[] = '    {';
        $lines[] = '        // Non-inlinable definitions (closures, pre-registered objects) and';
        $lines[] = '        // autowire-by-class delegate to the composed runtime container WITHOUT';
        $lines[] = '        // being memoised here — the runtime memo stays their single owner, so';
        $lines[] = '        // a resettable passthrough resets exactly once per request.';
        $lines[] = '        if (!isset(self::INLINED[$id])) {';
        $lines[] = '            return $this->runtime->get($id);';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        if (\\array_key_exists($id, $this->instances)) {';
        $lines[] = '            return $this->instances[$id];';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        // Built through the runtime container memo: a closure factory or an autowired';
        $lines[] = '        // service resolving the same id gets this very instance, and the runtime';
        $lines[] = '        // reset() covers it once per request (CARRY-06).';
        $lines[] = '        $instance = $this->runtime->memoise($id, fn(): object => match ($id) {';
        foreach ($inlinable as $id => $concrete) {
            $lines[] = sprintf('            %s => %s,', $this->quote($id), $this->emitConstruction($concrete));
        }
        $lines[] = '        });';
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
     * Emits `reset()`, a delegation: the runtime container holds every instance,
     * the inlined ones included (they are memoised there through `memoise()`), and
     * its cascade resets each exactly once per request. Cascading over the compiled
     * memo as well would reset the inlined services twice. SEC-04: nothing is
     * evicted, so inlined singletons keep their worker-lifetime identity.
     *
     * @return list<string>
     */
    private function emitReset(): array
    {
        return [
            '    public function reset(): void',
            '    {',
            '        // The runtime container holds every instance, the inlined ones included, and',
            '        // resets each once; the compiled memo is never evicted (SEC-04).',
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
