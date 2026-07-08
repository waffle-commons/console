<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * An ABSTRACT class registered as a definition concrete. The compiler rejects it
 * because `ReflectionClass::isInstantiable()` is false — it becomes a passthrough
 * delegated to the runtime container rather than an inlined `new` (AOT-01: the
 * non-instantiable/abstract-class rejection branch).
 */
abstract class AbstractServiceBase
{
    abstract public function kind(): string;
}
