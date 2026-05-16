<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Console\Command\RouteListCommand;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Routing\RouterInterface;
use WaffleTests\Commons\Console\AbstractTestCase;

#[CoversClass(RouteListCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class RouteListCommandTest extends AbstractTestCase
{
    public function testNameAndDescription(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $command = new RouteListCommand($router);

        static::assertSame('route:list', $command->getName());
        static::assertNotEmpty($command->getDescription());
    }

    public function testEmptyRouterShortCircuits(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRoutes')->willReturn([]);

        $output = new NullOutput();
        $exit = new RouteListCommand($router)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertNotEmpty($output->lines());
    }

    public function testPrintsTableForRegisteredRoutes(): void
    {
        /** @var array<array-key, array{classname: class-string, method: string, arguments: array<string, mixed>, path: string, name: non-falsy-string}> $routes */
        $routes = [
            [
                'classname' => 'App\\Controller\\Home',
                'method' => 'index',
                'arguments' => [],
                'path' => '/',
                'name' => 'home',
            ],
            [
                'classname' => 'App\\Controller\\User',
                'method' => 'show',
                'arguments' => ['id' => 'int'],
                'path' => '/users/{id}',
                'name' => 'user_show',
            ],
        ];

        $router = $this->createMock(RouterInterface::class);
        $router->method('getRoutes')->willReturn($routes);

        $output = new NullOutput();
        $exit = new RouteListCommand($router)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        $rendered = array_map(static fn(array $line): string => $line[0], $output->lines());
        $joined = implode("\n", $rendered);
        static::assertStringContainsString('home', $joined);
        static::assertStringContainsString('user_show', $joined);
        static::assertStringContainsString('/users/{id}', $joined);
        static::assertStringContainsString('2 route(s).', $joined);
    }
}
