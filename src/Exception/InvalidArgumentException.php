<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Exception;

use Throwable;
use Waffle\Commons\Contracts\Console\Exception\InvalidArgumentExceptionInterface;

/**
 * Thrown when a command receives malformed or missing required input.
 * Maps to {@see \Waffle\Commons\Contracts\Console\Enum\ExitCode::USAGE}.
 */
final class InvalidArgumentException extends ConsoleException implements InvalidArgumentExceptionInterface
{
    public function __construct(
        string $message,
        private(set) ?string $argumentName = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    #[\Override]
    public function getArgumentName(): ?string
    {
        return $this->argumentName;
    }
}
