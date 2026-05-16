<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Console\Command\SecurityAuditCommand;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Routing\RouterInterface;
use WaffleTests\Commons\Console\AbstractTestCase;
use WaffleTests\Commons\Console\Helper\GuardedController;
use WaffleTests\Commons\Console\Helper\UnguardedController;

#[CoversClass(SecurityAuditCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class SecurityAuditCommandTest extends AbstractTestCase
{
    public function testNameAndDescription(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $command = new SecurityAuditCommand($router);

        static::assertSame('security:audit', $command->getName());
        static::assertNotEmpty($command->getDescription());
    }

    public function testEmptyRouterExitsSuccess(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRoutes')->willReturn([]);

        $exit = new SecurityAuditCommand($router)->execute(new ArgvInput([]), new NullOutput());

        static::assertSame(ExitCode::SUCCESS->value, $exit);
    }

    public function testAllRoutesGuardedExitsSuccess(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router
            ->method('getRoutes')
            ->willReturn([
                [
                    'classname' => GuardedController::class,
                    'method' => 'read',
                    'arguments' => [],
                    'path' => '/items',
                    'name' => 'items_read',
                ],
                [
                    'classname' => GuardedController::class,
                    'method' => 'save',
                    'arguments' => [],
                    'path' => '/items',
                    'name' => 'items_save',
                ],
            ]);

        $output = new NullOutput();
        $exit = new SecurityAuditCommand($router)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertSame([], $output->errors(), 'No route should be flagged as unguarded');
    }

    public function testUnguardedRouteFlippedToUsageExit(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router
            ->method('getRoutes')
            ->willReturn([
                [
                    'classname' => GuardedController::class,
                    'method' => 'read',
                    'arguments' => [],
                    'path' => '/items',
                    'name' => 'items_read',
                ],
                [
                    'classname' => UnguardedController::class,
                    'method' => 'unsafe',
                    'arguments' => [],
                    'path' => '/items/unsafe',
                    'name' => 'items_unsafe',
                ],
            ]);

        $output = new NullOutput();
        $exit = new SecurityAuditCommand($router)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::USAGE->value, $exit);
        static::assertNotEmpty($output->errors());
        static::assertStringContainsString('UNGUARDED', $output->errors()[0]);
    }

    public function testNonExistentControllerProducesUnguardedRoute(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router
            ->method('getRoutes')
            ->willReturn([
                [
                    'classname' => '\\Not\\A\\Real\\Class',
                    'method' => 'whatever',
                    'arguments' => [],
                    'path' => '/ghost',
                    'name' => 'ghost_route',
                ],
            ]);

        $output = new NullOutput();
        $exit = new SecurityAuditCommand($router)->execute(new ArgvInput([]), $output);

        // Non-existent class → no voters collected → route is flagged as unguarded.
        static::assertSame(ExitCode::USAGE->value, $exit);
        static::assertNotEmpty($output->errors());
    }

    public function testRouteWithMissingMethodIsTreatedAsUnguarded(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router
            ->method('getRoutes')
            ->willReturn([
                [
                    'classname' => UnguardedController::class,
                    'method' => 'methodThatDoesNotExist',
                    'arguments' => [],
                    'path' => '/missing',
                    'name' => 'missing_method',
                ],
            ]);

        $output = new NullOutput();
        $exit = new SecurityAuditCommand($router)->execute(new ArgvInput([]), $output);

        static::assertSame(ExitCode::USAGE->value, $exit);
    }

    public function testCsrfRequirementIsSurfaced(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router
            ->method('getRoutes')
            ->willReturn([
                [
                    'classname' => GuardedController::class,
                    'method' => 'save',
                    'arguments' => [],
                    'path' => '/items',
                    'name' => 'items_save',
                ],
            ]);

        $output = new NullOutput();
        new SecurityAuditCommand($router)->execute(new ArgvInput([]), $output);

        $rendered = array_map(static fn(array $line): string => $line[0], $output->lines());
        static::assertStringContainsString('form:save', implode("\n", $rendered));
    }
}
