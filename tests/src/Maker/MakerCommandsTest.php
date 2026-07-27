<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Maker;

use PHPUnit\Framework\TestCase;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Console\Maker\AbstractMakerCommand;
use Waffle\Commons\Console\Maker\Command\MakeCommandCommand;
use Waffle\Commons\Console\Maker\Command\MakeControllerCommand;
use Waffle\Commons\Console\Maker\Command\MakeDtoCommand;
use Waffle\Commons\Console\Maker\Command\MakeEntityCommand;
use Waffle\Commons\Console\Maker\Command\MakeEventPairCommand;
use Waffle\Commons\Console\Maker\Command\MakeHttpClientCommand;
use Waffle\Commons\Console\Maker\Command\MakeMiddlewareCommand;
use Waffle\Commons\Console\Maker\Command\MakeRepositoryCommand;
use Waffle\Commons\Console\Maker\Command\MakeVoterCommand;
use Waffle\Commons\Console\Output\NullOutput;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

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

    public function testMakeEntityGeneratesFileWithPropertyHooks(): void
    {
        $command = new MakeEntityCommand();
        $input = new ArgvInput([
            'User',
            'id:string',
            'email:string',
            '--target=' . $this->tempDir . '/src/Entity',
        ]);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit);

        $expectedFile = $this->tempDir . '/src/Entity/User.php';
        static::assertFileExists($expectedFile);

        $content = (string) file_get_contents($expectedFile);
        static::assertStringContainsString('namespace TestApp\Entity;', $content);
        static::assertStringContainsString('final class User', $content);
        static::assertStringContainsString('public string $id {', $content);
        static::assertStringContainsString('public string $email {', $content);
        static::assertStringContainsString('__construct(string $id, string $email)', $content);
    }

    public function testMakeRepositoryGeneratesRepositoryAndMapperPair(): void
    {
        $command = new MakeRepositoryCommand();
        $input = new ArgvInput([
            'User',
            'id:string',
            'email:string',
            '--table=users',
            '--target=' . $this->tempDir . '/src/Repository',
        ]);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit);

        $repositoryFile = $this->tempDir . '/src/Repository/UserRepository.php';
        $mapperFile = $this->tempDir . '/src/Repository/UserMapper.php';

        static::assertFileExists($repositoryFile);
        static::assertFileExists($mapperFile);

        $repositoryContent = (string) file_get_contents($repositoryFile);
        static::assertStringContainsString('namespace TestApp\Repository;', $repositoryContent);
        static::assertStringContainsString('final readonly class UserRepository', $repositoryContent);
        static::assertStringContainsString('use TestApp\Entity\User;', $repositoryContent);
        static::assertStringContainsString('target: User::class', $repositoryContent);
        static::assertStringContainsString('mapper: new UserMapper()', $repositoryContent);
        static::assertStringContainsString("->from('users')", $repositoryContent);

        $mapperContent = (string) file_get_contents($mapperFile);
        static::assertStringContainsString('namespace TestApp\Repository;', $mapperContent);
        static::assertStringContainsString(
            'final readonly class UserMapper implements DataMapperInterface',
            $mapperContent,
        );
        static::assertStringContainsString("return 'users';", $mapperContent);
        static::assertStringContainsString("return 'id';", $mapperContent);
        static::assertStringContainsString("'id', 'email'", $mapperContent);
        static::assertStringContainsString("'email' => \$entity->email,", $mapperContent);
    }

    public function testMakeRepositoryAcceptsExplicitRepositorySuffixAndTableDefault(): void
    {
        $command = new MakeRepositoryCommand();
        $input = new ArgvInput([
            'OrderRepository',
            '--target=' . $this->tempDir . '/src/Repository',
        ]);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit);

        $mapperContent = (string) file_get_contents($this->tempDir . '/src/Repository/OrderMapper.php');
        static::assertStringContainsString("return 'orders';", $mapperContent);
        static::assertStringContainsString("'id'", $mapperContent);
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

    public function testWriteFileRefusesContentThatFailsSyntaxCheck(): void
    {
        // SEC-... codegen-injection backstop. Every Make*Command input is now
        // validated (assertValidIdentifier() / assertSafeForQuotedStub() /
        // assertValidInteger()) before it ever reaches a stub, so there's no
        // longer a CLI field left to smuggle invalid syntax through the
        // normal command pipeline — this now exercises writeFile()'s php -l
        // check directly, proving it still refuses genuinely broken PHP as
        // an independent line of defense (e.g. against a future stub or
        // generator that forgets to validate its own input).
        $command = new readonly class extends AbstractMakerCommand {
            #[\Override]
            public function getName(): string
            {
                return 'test:write-file';
            }

            #[\Override]
            public function getDescription(): string
            {
                return '';
            }

            #[\Override]
            public function execute(InputInterface $input, OutputInterface $output): int
            {
                return ExitCode::SUCCESS->value;
            }

            public function writePublic(string $filepath, string $content, bool $force, OutputInterface $output): void
            {
                $this->writeFile($filepath, $content, $force, $output);
            }
        };

        $filepath = $this->tempDir . '/src/Broken.php';
        $output = new NullOutput();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('syntax check');

        try {
            $command->writePublic($filepath, "<?php\n\ndeclare(strict_types=1);\n\nclass Broken {", false, $output);
        } finally {
            static::assertFileDoesNotExist($filepath);
        }
    }

    public function testControllerRejectsHostileRoute(): void
    {
        // --route lands inside a single-quoted stub slot (`path: '{{ ROUTE }}'`);
        // a stray quote used to reach the generated file unvalidated.
        $command = new MakeControllerCommand();
        $input = new ArgvInput([
            'HostileRouteController',
            "--route=orders'; system(\$_GET[0]); //",
            '--target=' . $this->tempDir . '/src/Controller',
        ]);
        $output = new NullOutput();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid route');

        $command->execute($input, $output);
    }

    public function testControllerRejectsHostilePriority(): void
    {
        // --priority lands as a BARE `priority: {{ PRIORITY }}` integer
        // literal — no quotes at all to break out of.
        $command = new MakeControllerCommand();
        $input = new ArgvInput([
            'HostilePriorityController',
            '--priority=0); system($_GET[0]); //',
            '--target=' . $this->tempDir . '/src/Controller',
        ]);
        $output = new NullOutput();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid priority');

        $command->execute($input, $output);
    }

    public function testControllerAcceptsNegativePriority(): void
    {
        // Negative priorities are legitimate (catch-all routes) — the
        // integer grammar must not reject the leading `-`.
        $command = new MakeControllerCommand();
        $input = new ArgvInput([
            'NegativePriorityController',
            '--priority=-1000',
            '--target=' . $this->tempDir . '/src/Controller',
        ]);
        $output = new NullOutput();

        $exit = $command->execute($input, $output);
        static::assertSame(ExitCode::SUCCESS->value, $exit);

        $content = (string) file_get_contents($this->tempDir . '/src/Controller/NegativePriorityController.php');
        static::assertStringContainsString('priority: -1000', $content);
    }

    public function testMakeCommandRejectsHostileCommandName(): void
    {
        $command = new MakeCommandCommand();
        $input = new ArgvInput([
            'ImportDataCommand',
            "--command-name=app:import'; system(\$_GET[0]); //",
            '--target=' . $this->tempDir . '/src/Console/Command',
        ]);
        $output = new NullOutput();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid command name');

        $command->execute($input, $output);
    }

    public function testMakeHttpClientRejectsHostileBaseUri(): void
    {
        $command = new MakeHttpClientCommand();
        $input = new ArgvInput([
            'ExternalApiClient',
            "--base-uri=https://evil'; system(\$_GET[0]); //",
            '--target=' . $this->tempDir . '/src/Service',
        ]);
        $output = new NullOutput();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid base URI');

        $command->execute($input, $output);
    }

    public function testClassNameRejectsPathTraversalAttempt(): void
    {
        // The Blocking finding this closes: a className smuggling `../`
        // segments used to reach resolveNamespaceAndPath() unvalidated and
        // could write outside the target directory tree. assertValidIdentifier()
        // now rejects it before any path is built or any stub is rendered.
        $command = new MakeControllerCommand();
        $input = new ArgvInput([
            '../../../../etc/cron.d/evil',
            '--target=' . $this->tempDir . '/src/Controller',
        ]);
        $output = new NullOutput();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid name');

        $command->execute($input, $output);
    }

    public function testClassNameRejectsCodegenBreakoutAttempt(): void
    {
        // A className crafted to close the intended class early and open a
        // second one is syntactically VALID PHP (php -l alone wouldn't catch
        // it) — must be rejected by the identifier grammar instead.
        $command = new MakeControllerCommand();
        $input = new ArgvInput([
            'Evil { public static function pwn() { system($_GET[0]); } } class Filler',
            '--target=' . $this->tempDir . '/src/Controller',
        ]);
        $output = new NullOutput();

        $this->expectException(\InvalidArgumentException::class);

        $command->execute($input, $output);
    }

    public function testMakeRepositoryRejectsHostileIdentityField(): void
    {
        // $identity lands as a BARE `$entity->{{ IDENTITY }}` property-access
        // expression in the generated mapper — no quotes to break out of, it
        // just needs to not be a valid identifier to inject arbitrary code.
        $command = new MakeRepositoryCommand();
        $input = new ArgvInput([
            'User',
            'name:string',
            '--identity=id; system($_GET[0])',
            '--target=' . $this->tempDir . '/src/Repository',
        ]);
        $output = new NullOutput();

        $this->expectException(\InvalidArgumentException::class);

        $command->execute($input, $output);
    }

    public function testMakeRepositoryRejectsHostileTableName(): void
    {
        $command = new MakeRepositoryCommand();
        $input = new ArgvInput([
            'User',
            '--table=' . "users'; system(\$_GET[0]); //",
            '--target=' . $this->tempDir . '/src/Repository',
        ]);
        $output = new NullOutput();

        $this->expectException(\InvalidArgumentException::class);

        $command->execute($input, $output);
    }

    public function testMakeRepositoryRejectsHostileFieldName(): void
    {
        $command = new MakeRepositoryCommand();
        $input = new ArgvInput([
            'User',
            "evil'; system(\$_GET[0]); //:string",
            '--target=' . $this->tempDir . '/src/Repository',
        ]);
        $output = new NullOutput();

        $this->expectException(\InvalidArgumentException::class);

        $command->execute($input, $output);
    }

    public function testMakerCommandsMetadataAndEdgeCases(): void
    {
        $commands = [
            new MakeControllerCommand(),
            new MakeDtoCommand(),
            new MakeEntityCommand(),
            new MakeMiddlewareCommand(),
            new MakeRepositoryCommand(),
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
