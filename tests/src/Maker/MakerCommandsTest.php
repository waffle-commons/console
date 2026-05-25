<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Maker;

use PHPUnit\Framework\TestCase;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Maker\Command\MakeCommandCommand;
use Waffle\Commons\Console\Maker\Command\MakeControllerCommand;
use Waffle\Commons\Console\Maker\Command\MakeDtoCommand;
use Waffle\Commons\Console\Maker\Command\MakeEventPairCommand;
use Waffle\Commons\Console\Maker\Command\MakeHttpClientCommand;
use Waffle\Commons\Console\Maker\Command\MakeMiddlewareCommand;
use Waffle\Commons\Console\Maker\Command\MakeVoterCommand;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;

final class MakerCommandsTest extends TestCase
{
    private string $tempDir;

    #[\Override]
    protected function setUp(): void
    {
        $real = realpath(__DIR__ . '/../../../var');
        $this->tempDir = ($real !== false ? $real : '') . '/tmp_test_maker_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
        mkdir($this->tempDir . '/src', 0o755, true);

        // Dummy composer.json for PSR-4 namespace resolution
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'TestApp\\' => 'src/',
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', (string) json_encode($composerJson));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testMakeControllerGeneratesFile(): void
    {
        $command = new MakeControllerCommand();
        $input = new ArgvInput([
            'HomeController',
            '--route=/home',
            '--priority=10',
            '--target=' . $this->tempDir . '/src/Controller',
        ]);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit);

        $expectedFile = $this->tempDir . '/src/Controller/HomeController.php';
        static::assertFileExists($expectedFile);

        $content = (string) file_get_contents($expectedFile);
        static::assertStringContainsString('namespace TestApp\Controller;', $content);
        static::assertStringContainsString('class HomeController extends BaseController', $content);
        static::assertStringContainsString('#[Route(path: \'/home\', priority: 10)]', $content);
    }

    public function testMakeDtoGeneratesFileWithPropertyHooks(): void
    {
        $command = new MakeDtoCommand();
        $input = new ArgvInput([
            'UserDto',
            'email:string',
            'age:int',
            '--target=' . $this->tempDir . '/src/Dto',
        ]);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit);

        $expectedFile = $this->tempDir . '/src/Dto/UserDto.php';
        static::assertFileExists($expectedFile);

        $content = (string) file_get_contents($expectedFile);
        static::assertStringContainsString('namespace TestApp\Dto;', $content);
        static::assertStringContainsString('final class UserDto', $content);
        static::assertStringContainsString('public string $email {', $content);
        static::assertStringContainsString('public int $age {', $content);
        static::assertStringContainsString('__construct(string $email, int $age)', $content);
    }

    public function testMakeMiddlewareGeneratesFile(): void
    {
        $command = new MakeMiddlewareCommand();
        $input = new ArgvInput([
            'AuthMiddleware',
            '--target=' . $this->tempDir . '/src/Middleware',
        ]);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit);

        $expectedFile = $this->tempDir . '/src/Middleware/AuthMiddleware.php';
        static::assertFileExists($expectedFile);

        $content = (string) file_get_contents($expectedFile);
        static::assertStringContainsString('namespace TestApp\Middleware;', $content);
        static::assertStringContainsString('implements MiddlewareInterface', $content);
    }

    public function testMakeVoterGeneratesFile(): void
    {
        $command = new MakeVoterCommand();
        $input = new ArgvInput([
            'ArticleVoter',
            '--target=' . $this->tempDir . '/src/Security/Voter',
        ]);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit);

        $expectedFile = $this->tempDir . '/src/Security/Voter/ArticleVoter.php';
        static::assertFileExists($expectedFile);

        $content = (string) file_get_contents($expectedFile);
        static::assertStringContainsString('namespace TestApp\Security\Voter;', $content);
        static::assertStringContainsString('implements VoterInterface', $content);
        static::assertStringContainsString('return false;', $content);
    }

    public function testMakeHttpClientGeneratesFile(): void
    {
        $command = new MakeHttpClientCommand();
        $input = new ArgvInput([
            'ExternalApiClient',
            '--base-uri=https://api.external.com',
            '--target=' . $this->tempDir . '/src/Service',
        ]);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit);

        $expectedFile = $this->tempDir . '/src/Service/ExternalApiClient.php';
        static::assertFileExists($expectedFile);

        $content = (string) file_get_contents($expectedFile);
        static::assertStringContainsString('namespace TestApp\Service;', $content);
        static::assertStringContainsString('class ExternalApiClient', $content);
        static::assertStringContainsString('https://api.external.com', $content);
    }

    public function testMakeCommandGeneratesFile(): void
    {
        $command = new MakeCommandCommand();
        $input = new ArgvInput([
            'ImportDataCommand',
            '--command-name=app:import-data',
            '--target=' . $this->tempDir . '/src/Console/Command',
        ]);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit);

        $expectedFile = $this->tempDir . '/src/Console/Command/ImportDataCommand.php';
        static::assertFileExists($expectedFile);

        $content = (string) file_get_contents($expectedFile);
        static::assertStringContainsString('namespace TestApp\Console\Command;', $content);
        static::assertStringContainsString('app:import-data', $content);
    }

    public function testMakeEventPairGeneratesCoordinatedFiles(): void
    {
        $command = new MakeEventPairCommand();
        $input = new ArgvInput([
            'OrderShipped',
            '--target=' . $this->tempDir . '/src/',
        ]);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit);

        $eventFile = $this->tempDir . '/src/Event/OrderShippedEvent.php';
        $listenerFile = $this->tempDir . '/src/Event/Listener/OrderShippedListener.php';

        static::assertFileExists($eventFile);
        static::assertFileExists($listenerFile);

        $eventContent = (string) file_get_contents($eventFile);
        static::assertStringContainsString('namespace TestApp\Event;', $eventContent);
        static::assertStringContainsString('class OrderShippedEvent extends AbstractStoppableEvent', $eventContent);

        $listenerContent = (string) file_get_contents($listenerFile);
        static::assertStringContainsString('namespace TestApp\Event\Listener;', $listenerContent);
        static::assertStringContainsString('OrderShippedListener', $listenerContent);
        static::assertStringContainsString('#[AsEventListener]', $listenerContent);
        static::assertStringContainsString('OrderShippedEvent $event', $listenerContent);
    }

    public function testPreventOverwriteWithoutForce(): void
    {
        $command = new MakeControllerCommand();
        $input = new ArgvInput([
            'AboutController',
            '--target=' . $this->tempDir . '/src/Controller',
        ]);
        $output = new NullOutput();

        // First write succeeds
        $exit1 = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit1);

        // Second write fails without force
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $command->execute($input, $output);
    }

    public function testForceOverwritesExistingFile(): void
    {
        $command = new MakeControllerCommand();
        $input = new ArgvInput([
            'AboutController',
            '--target=' . $this->tempDir . '/src/Controller',
        ]);
        $output = new NullOutput();

        $exit1 = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit1);

        // Second write succeeds with force
        $inputForce = new ArgvInput([
            'AboutController',
            '--force',
            '--target=' . $this->tempDir . '/src/Controller',
        ]);
        $exit2 = $command->execute($inputForce, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit2);
    }

    public function testMakerCommandsMetadataAndEdgeCases(): void
    {
        $commands = [
            new MakeControllerCommand(),
            new MakeDtoCommand(),
            new MakeMiddlewareCommand(),
            new MakeVoterCommand(),
            new MakeHttpClientCommand(),
            new MakeCommandCommand(),
            new MakeEventPairCommand(),
        ];

        foreach ($commands as $cmd) {
            static::assertNotEmpty($cmd->getName());
            static::assertNotEmpty($cmd->getDescription());
            static::assertSame('', $cmd->getHelp());
            static::assertSame($cmd->getName(), $cmd->getSynopsis());

            // Empty name validation checks
            $input = new ArgvInput(['']);
            $output = new NullOutput();
            try {
                $cmd->execute($input, $output);
                static::fail('Expected InvalidArgumentException for empty name on command ' . $cmd->getName());
            } catch (\InvalidArgumentException $e) {
                static::assertStringContainsString('[ERROR]', $e->getMessage());
            }
        }
    }

    public function testResolveTargetDirAutoResolvesToSrcSubfolder(): void
    {
        $command = new MakeControllerCommand();
        $input = new ArgvInput([
            'TestAutoController',
        ]);
        $output = new NullOutput();

        $cwd = getcwd();
        chdir($this->tempDir);
        try {
            $exit = $command->execute($input, $output);
            static::assertSame(ExitCode::SUCCESS->value, $exit);
            static::assertFileExists($this->tempDir . '/src/Controller/TestAutoController.php');
        } finally {
            if ($cwd !== false) {
                chdir($cwd);
            }
        }
    }

    public function testResolveTargetDirWithNonExistentRelativePath(): void
    {
        $cmd = new MakeControllerCommand();
        $uniqueSubdir = 'src/nonexistent_maker_sub_' . uniqid();
        $input = new ArgvInput([
            'TestController',
            '--target=' . $uniqueSubdir,
        ]);
        $output = new NullOutput();

        $cwd = getcwd();
        chdir($this->tempDir);
        try {
            $exit = $cmd->execute($input, $output);
            static::assertSame(ExitCode::SUCCESS->value, $exit);
            static::assertDirectoryExists($this->tempDir . '/' . $uniqueSubdir);
            static::assertFileExists($this->tempDir . '/' . $uniqueSubdir . '/TestController.php');
        } finally {
            if ($cwd !== false) {
                chdir($cwd);
            }
        }
    }

    public function testComposerJsonMissingThrowsException(): void
    {
        $cmd = new MakeControllerCommand();
        $noComposerDir = '/tmp/no_composer_test_' . uniqid();
        mkdir($noComposerDir, 0o755, true);

        $input = new ArgvInput([
            'FailController',
            '--target=' . $noComposerDir,
        ]);
        $output = new NullOutput();

        try {
            $cmd->execute($input, $output);
            static::fail('Expected RuntimeException for missing composer.json');
        } catch (\RuntimeException $e) {
            static::assertStringContainsString('composer.json not found', $e->getMessage());
        } finally {
            if (is_dir($noComposerDir)) {
                rmdir($noComposerDir);
            }
        }
    }

    public function testMalformedComposerJsonThrowsException(): void
    {
        $cmd = new MakeControllerCommand();
        $malformedDir = $this->tempDir . '/malformed';
        mkdir($malformedDir);
        file_put_contents($malformedDir . '/composer.json', '{invalid_json');

        $input = new ArgvInput([
            'FailController',
            '--target=' . $malformedDir,
        ]);
        $output = new NullOutput();

        try {
            $cmd->execute($input, $output);
            static::fail('Expected RuntimeException for malformed composer.json');
        } catch (\RuntimeException $e) {
            static::assertStringContainsString('composer.json', $e->getMessage());
        }
    }

    private function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
            $scan = scandir($path);
            if ($scan !== false) {
                $files = array_diff($scan, ['.', '..']);
                foreach ($files as $file) {
                    $this->removeDirectory($path . '/' . $file);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
