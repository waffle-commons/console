<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Console\Exception\CommandNotFoundException;
use Waffle\Commons\Console\Exception\ConsoleException;
use Waffle\Commons\Console\Exception\InvalidArgumentException;
use Waffle\Commons\Contracts\Console\Exception\CommandNotFoundExceptionInterface;
use Waffle\Commons\Contracts\Console\Exception\ConsoleExceptionInterface;
use Waffle\Commons\Contracts\Console\Exception\InvalidArgumentExceptionInterface;
use WaffleTests\Commons\Console\AbstractTestCase;

#[CoversClass(ConsoleException::class)]
#[CoversClass(CommandNotFoundException::class)]
#[CoversClass(InvalidArgumentException::class)]
final class ConsoleExceptionsTest extends AbstractTestCase
{
    public function testConsoleExceptionImplementsContractMarker(): void
    {
        static::assertInstanceOf(ConsoleExceptionInterface::class, new ConsoleException('boom'));
    }

    public function testCommandNotFoundCarriesNameAndDefaultMessage(): void
    {
        $e = new CommandNotFoundException(requestedCommand: 'cache:doit');

        static::assertInstanceOf(CommandNotFoundExceptionInterface::class, $e);
        static::assertSame('cache:doit', $e->getRequestedCommand());
        static::assertStringContainsString('cache:doit', $e->getMessage());
    }

    public function testCommandNotFoundAcceptsCustomMessage(): void
    {
        $e = new CommandNotFoundException(requestedCommand: 'x', message: 'custom');

        static::assertSame('custom', $e->getMessage());
    }

    public function testInvalidArgumentCarriesArgumentName(): void
    {
        $e = new InvalidArgumentException(message: 'missing --env', argumentName: 'env');

        static::assertInstanceOf(InvalidArgumentExceptionInterface::class, $e);
        static::assertSame('env', $e->getArgumentName());
    }

    public function testInvalidArgumentArgumentNameIsOptional(): void
    {
        $e = new InvalidArgumentException('malformed input');

        static::assertNull($e->getArgumentName());
    }
}
