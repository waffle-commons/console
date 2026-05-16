<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Exception;

use RuntimeException;
use Waffle\Commons\Contracts\Console\Exception\ConsoleExceptionInterface;

/**
 * Concrete base for all Console subsystem failures.
 *
 * Extends `RuntimeException` because console failures are runtime-only —
 * they signal a problem with the invocation, never a programmer bug.
 */
class ConsoleException extends RuntimeException implements ConsoleExceptionInterface {}
