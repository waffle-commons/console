<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use Waffle\Commons\Contracts\Console\CommandInterface;

/**
 * Convenience base for `CommandInterface` implementations.
 *
 * Provides empty `getHelp()` and `getSynopsis()` defaults so leaf commands
 * implement only `getName()`, `getDescription()`, and `execute()`. Override
 * the helpers when the extra context is genuinely useful.
 */
abstract readonly class AbstractCommand implements CommandInterface
{
    #[\Override]
    public function getHelp(): string
    {
        return '';
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return $this->getName();
    }
}
