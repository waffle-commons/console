<?php

declare(strict_types=1);

namespace Waffle\Commons\Console;

use Throwable;
use Waffle\Commons\Console\Exception\CommandNotFoundException;
use Waffle\Commons\Console\Input\ArgvInput;
use Waffle\Commons\Contracts\Console\CommandInterface;
use Waffle\Commons\Contracts\Console\ConsoleApplicationInterface;
use Waffle\Commons\Contracts\Console\Constant;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\Enum\Verbosity;
use Waffle\Commons\Contracts\Console\Exception\ConsoleExceptionInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

/**
 * Concrete implementation of the Waffle Console application (RFC-012).
 *
 * Commands are registered EXPLICITLY at boot — no auto-discovery — and resolve
 * their dependencies through constructor injection (RFC-012 §4). This class
 * does no DI itself; callers wire commands and pass them in.
 *
 * Invoke pattern:
 *
 *     $app = new ConsoleApplication('Waffle', '0.1.0', $output);
 *     $app->add(new CacheClearCommand($cache));
 *     $app->add(new RouteListCommand($router));
 *     exit($app->run());
 */
final class ConsoleApplication implements ConsoleApplicationInterface
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    /** @var list<string> Raw argv excluding the script path (i.e. argv[1..]). */
    private array $argv;

    public function __construct(
        private readonly string $name = Constant::DEFAULT_APP_NAME,
        private readonly string $version = '0.0.0',
        private readonly OutputInterface $output = new \Waffle\Commons\Console\Output\StreamOutput(),
        ?array $argv = null,
    ) {
        $rawArgv = $argv ?? ($_SERVER['argv'] ?? []);
        // Drop argv[0] (the script path) — leaves [command-name, ...args/options].
        $tail = is_array($rawArgv) ? array_slice($rawArgv, 1) : [];
        $this->argv = array_values(array_filter($tail, 'is_string'));
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[\Override]
    public function getVersion(): string
    {
        return $this->version;
    }

    #[\Override]
    public function add(CommandInterface $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    #[\Override]
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->commands);
    }

    #[\Override]
    public function find(string $name): CommandInterface
    {
        if (!$this->has($name)) {
            throw new CommandNotFoundException(requestedCommand: $name);
        }
        return $this->commands[$name];
    }

    #[\Override]
    public function all(): array
    {
        return $this->commands;
    }

    #[\Override]
    public function run(): int
    {
        if ($this->argv === []) {
            $this->printUsage();
            return ExitCode::USAGE->value;
        }

        $commandName = $this->argv[0];

        // Built-in: `list` prints all registered commands.
        if ($commandName === Constant::COMMAND_LIST) {
            $this->printUsage();
            return ExitCode::SUCCESS->value;
        }

        $remaining = array_slice($this->argv, 1);
        $input = new ArgvInput($remaining);

        // Adjust verbosity from -v/-vv/-vvv/--verbose flags before dispatch.
        $this->configureVerbosity($input->getOptions());

        try {
            $command = $this->find($commandName);
            return $command->execute($input, $this->output);
        } catch (ConsoleExceptionInterface $e) {
            $this->output->writeError(sprintf('[error] %s', $e->getMessage()));
            return ExitCode::FAILURE->value;
        } catch (Throwable $e) {
            $this->output->writeError(sprintf('[error] %s', $e->getMessage()));
            return ExitCode::FAILURE->value;
        }
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function configureVerbosity(array $options): void
    {
        if (array_key_exists('vvv', $options) || array_key_exists('debug', $options)) {
            $this->output->setVerbosity(Verbosity::DEBUG);
            return;
        }
        if (array_key_exists('vv', $options) || array_key_exists('very-verbose', $options)) {
            $this->output->setVerbosity(Verbosity::VERY_VERBOSE);
            return;
        }
        if (array_key_exists('v', $options) || array_key_exists('verbose', $options)) {
            $this->output->setVerbosity(Verbosity::VERBOSE);
            return;
        }
        if (array_key_exists('quiet', $options)) {
            $this->output->setVerbosity(Verbosity::QUIET);
        }
    }

    private function printUsage(): void
    {
        $this->output->writeLine(sprintf('%s %s', $this->name, $this->version));
        $this->output->writeLine('');
        $this->output->writeLine('Available commands:');
        if ($this->commands === []) {
            $this->output->writeLine('  (no commands registered)');
            return;
        }
        $maxName = max(array_map('strlen', array_keys($this->commands)));
        foreach ($this->commands as $name => $command) {
            $this->output->writeLine(sprintf(
                '  %s  %s',
                str_pad($name, $maxName),
                $command->getDescription(),
            ));
        }
    }
}
