<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Exception;

/**
 * Raised by the AOT-01 {@see \Waffle\Commons\Console\Compiler\ContainerCompiler}
 * when a service graph cannot be compiled to static PHP source (e.g. a runtime
 * container that does not expose an inspectable definition map).
 */
final class CompilerException extends ConsoleException {}
