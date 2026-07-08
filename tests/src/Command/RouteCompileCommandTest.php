<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Console\Command\RouteCompileCommand;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Routing\MatchedRoute;
use Waffle\Commons\Contracts\Routing\RouterInterface;
use WaffleTests\Commons\Console\AbstractTestCase;

#[CoversClass(RouteCompileCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class RouteCompileCommandTest extends AbstractTestCase
{
    private string $artifact = '';

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->artifact !== '' && is_file($this->artifact)) {
            @unlink($this->artifact);
        }
        parent::tearDown();
    }

    /**
     * @return list<MatchedRoute>
     */
    private function fixtureRoutes(): array
    {
        return [
            new MatchedRoute(
                className: 'App\\Controller\\Home',
                method: 'index',
                arguments: [],
                path: '/',
                name: 'home',
                methods: ['GET'],
            ),
            new MatchedRoute(
                className: 'App\\Controller\\User',
                method: 'show',
                arguments: ['id' => 'int'],
                path: '/users/{id}',
                name: 'users_show',
                methods: ['GET'],
            ),
        ];
    }

    public function testNameDescriptionAndSynopsis(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $command = new RouteCompileCommand($router);

        static::assertSame('route:compile', $command->getName());
        static::assertNotEmpty($command->getDescription());
        static::assertNotEmpty($command->getHelp());
        static::assertStringContainsString('route:compile', $command->getSynopsis());
    }

    public function testWritesRouteListArtifactByDefault(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRoutes')->willReturn($this->fixtureRoutes());

        $this->artifact = sys_get_temp_dir() . '/aot/routes_' . uniqid() . '.php';
        $command = new RouteCompileCommand($router);

        $input = new ArgvInput([$this->artifact]);
        $input->bindArgumentNames(['artifact-path']);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertFileExists($this->artifact);

        // The artifact rehydrates to the exact route list (round-trip).
        /** @var mixed $rehydrated */
        $rehydrated = require $this->artifact;
        static::assertIsArray($rehydrated);
        static::assertCount(2, $rehydrated);
        static::assertContainsOnlyInstancesOf(MatchedRoute::class, $rehydrated);
    }

    public function testUsesInjectedTrieCompilerWhenProvided(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRoutes')->willReturn($this->fixtureRoutes());

        $this->artifact = sys_get_temp_dir() . '/aot/trie_' . uniqid() . '.php';

        // App-wired trie compiler: turns the route list into a plain trie array.
        $command = new RouteCompileCommand($router, static fn(array $routes): array => [
            'compiled' => true,
            'count' => count($routes),
        ]);

        $input = new ArgvInput([$this->artifact]);
        $input->bindArgumentNames(['artifact-path']);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        /** @var mixed $rehydrated */
        $rehydrated = require $this->artifact;
        static::assertSame(['compiled' => true, 'count' => 2], $rehydrated);
    }

    public function testReportsConfigErrorWhenArtifactDirectoryCannotBeCreated(): void
    {
        // The parent of the artifact path is a regular FILE, so writeArtifact()'s
        // mkdir() fails and returns false — the command reports a CONFIG error.
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRoutes')->willReturn($this->fixtureRoutes());

        $blocker = sys_get_temp_dir() . '/aot_route_blocker_' . uniqid();
        file_put_contents($blocker, 'x');
        $path = $blocker . '/nested/routes.trie.php';

        try {
            $command = new RouteCompileCommand($router);

            $input = new ArgvInput([$path]);
            $input->bindArgumentNames(['artifact-path']);
            $output = new NullOutput();

            // mkdir() emits a warning when its parent path is a file; the command's
            // own guard handles the failure, so the warning is expected and muted.
            $exit = @$command->execute($input, $output);

            static::assertSame(ExitCode::CONFIG->value, $exit);
            static::assertNotEmpty($output->errors());
            static::assertStringContainsString('route artifact', implode("\n", $output->errors()));
        } finally {
            @unlink($blocker);
        }
    }

    public function testReportsFailureWhenRouterThrows(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRoutes')->willThrowException(new \RuntimeException('boom'));

        $this->artifact = sys_get_temp_dir() . '/aot/fail_' . uniqid() . '.php';
        $command = new RouteCompileCommand($router);

        $input = new ArgvInput([$this->artifact]);
        $input->bindArgumentNames(['artifact-path']);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);

        static::assertSame(ExitCode::FAILURE->value, $exit);
        static::assertNotEmpty($output->errors());
    }
}
