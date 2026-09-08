<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Console\Command\ContainerCompileCommand;
use Waffle\Commons\Console\Compiler\ContainerCompiler;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use WaffleTests\Commons\Console\AbstractTestCase;
use WaffleTests\Commons\Console\Compiler\Fixture\FakeRuntimeContainer;
use WaffleTests\Commons\Console\Compiler\Fixture\LeafService;
use WaffleTests\Commons\Console\Compiler\Fixture\MidService;

#[CoversClass(ContainerCompileCommand::class)]
final class ContainerCompileCommandTest extends AbstractTestCase
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

    public function testNameDescriptionAndSynopsis(): void
    {
        $command = new ContainerCompileCommand(new FakeRuntimeContainer());

        static::assertSame('container:compile', $command->getName());
        static::assertNotEmpty($command->getDescription());
        static::assertNotEmpty($command->getHelp());
        static::assertStringContainsString('container:compile', $command->getSynopsis());
    }

    public function testWritesCompiledArtifactToTargetPath(): void
    {
        $container = new FakeRuntimeContainer();
        $container->set(LeafService::class, LeafService::class);
        $container->set(MidService::class, MidService::class);

        $this->artifact = sys_get_temp_dir() . '/aot/CompiledContainer_' . uniqid() . '.php';

        $command = new ContainerCompileCommand(
            $container,
            new ContainerCompiler(),
            'WaffleTests\\Commons\\Console\\Generated',
            'CompiledFromCommand',
            FakeRuntimeContainer::class,
        );

        $input = new ArgvInput([$this->artifact]);
        $input->bindArgumentNames(['artifact-path']);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);

        static::assertSame(ExitCode::SUCCESS->value, $exit);
        static::assertFileExists($this->artifact);

        $source = (string) file_get_contents($this->artifact);
        static::assertStringContainsString('CompiledFromCommand', $source);
        static::assertStringContainsString('CompiledContainerInterface', $source);
        // SEC-04 residual shape: get() guards on the INLINED membership map and the
        // delegate path returns immediately — passthroughs are never memoised (no
        // default arm writing into the compiled memo).
        static::assertStringContainsString('private const array INLINED = [', $source);
        static::assertStringContainsString('if (!isset(self::INLINED[$id])) {', $source);
        static::assertStringNotContainsString('default =>', $source);
        static::assertSame([], $output->errors());
    }

    public function testReportsConfigErrorWhenArtifactDirectoryCannotBeCreated(): void
    {
        // The parent of the artifact path is a regular FILE, so mkdir() of the
        // directory cannot succeed — the command must report a CONFIG error.
        $container = new FakeRuntimeContainer();
        $container->set(LeafService::class, LeafService::class);

        $blocker = sys_get_temp_dir() . '/aot_blocker_' . uniqid();
        file_put_contents($blocker, 'x');
        $this->artifact = $blocker . '/nested/CompiledContainer.php';

        try {
            $command = new ContainerCompileCommand(
                $container,
                new ContainerCompiler(),
                'WaffleTests\\Commons\\Console\\Generated',
                'CompiledMkdirFail',
                FakeRuntimeContainer::class,
            );

            $input = new ArgvInput([$this->artifact]);
            $input->bindArgumentNames(['artifact-path']);
            $output = new NullOutput();

            // mkdir() emits a warning when its parent path is a file; the command's
            // own guard handles the failure, so the warning is expected and muted.
            $exit = @$command->execute($input, $output);

            static::assertSame(ExitCode::CONFIG->value, $exit);
            static::assertNotEmpty($output->errors());
            static::assertStringContainsString('directory', implode("\n", $output->errors()));
        } finally {
            @unlink($blocker);
            $this->artifact = '';
        }
    }

    public function testReportsConfigErrorWhenArtifactCannotBeWritten(): void
    {
        // The artifact path is an existing DIRECTORY, so file_put_contents() fails
        // (its parent already exists, so mkdir is skipped) — CONFIG error.
        $container = new FakeRuntimeContainer();
        $container->set(LeafService::class, LeafService::class);

        $dirAsArtifact = sys_get_temp_dir() . '/aot_dir_' . uniqid();
        mkdir($dirAsArtifact, 0o775, true);

        try {
            $command = new ContainerCompileCommand(
                $container,
                new ContainerCompiler(),
                'WaffleTests\\Commons\\Console\\Generated',
                'CompiledWriteFail',
                FakeRuntimeContainer::class,
            );

            $input = new ArgvInput([$dirAsArtifact]);
            $input->bindArgumentNames(['artifact-path']);
            $output = new NullOutput();

            $exit = @$command->execute($input, $output);

            static::assertSame(ExitCode::CONFIG->value, $exit);
            static::assertNotEmpty($output->errors());
            static::assertStringContainsString('write', implode("\n", $output->errors()));
        } finally {
            @rmdir($dirAsArtifact);
        }
    }

    public function testReportsFailureWhenContainerHasNoDefinitionsMap(): void
    {
        $opaque = new class implements \Waffle\Commons\Contracts\Container\ContainerInterface {
            #[\Override]
            public function get(string $id): mixed
            {
                return null;
            }

            #[\Override]
            public function has(string $id): bool
            {
                return false;
            }

            #[\Override]
            public function set(string $id, object|callable|string $concrete): void {}

            #[\Override]
            public function reset(): void {}
        };

        $this->artifact = sys_get_temp_dir() . '/aot/never_' . uniqid() . '.php';
        $command = new ContainerCompileCommand($opaque);
        $input = new ArgvInput([$this->artifact]);
        $input->bindArgumentNames(['artifact-path']);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);

        static::assertSame(ExitCode::FAILURE->value, $exit);
        static::assertNotEmpty($output->errors());
    }
}
