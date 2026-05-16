<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Exception;

use Throwable;
use Waffle\Commons\Contracts\Console\Exception\CommandNotFoundExceptionInterface;

/**
 * Thrown when `ConsoleApplication::find()` cannot resolve the requested name.
 */
final class CommandNotFoundException extends ConsoleException implements CommandNotFoundExceptionInterface
{
    public function __construct(
        private(set) string $requestedCommand,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = sprintf('Command "%s" is not registered.', $requestedCommand);
        }
        parent::__construct($message, $code, $previous);
    }

    #[\Override]
    public function getRequestedCommand(): string
    {
        return $this->requestedCommand;
    }
}
