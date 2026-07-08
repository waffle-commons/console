<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use Throwable;
use Waffle\Commons\Console\Compiler\ContainerCompiler;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\Enum\Verbosity;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;
use Waffle\Commons\Contracts\Container\ContainerInterface;

/**
 * `waffle container:compile` — emits the AOT-01 compiled-container artifact.
 *
 * Runs {@see ContainerCompiler} over the booted, locked runtime container and
 * writes the generated `CompiledContainer` class to disk (default:
 * `var/cache/CompiledContainer.php`, overridable with a positional argument).
 * The application boots and locks the container, then wires it into this command;
 * the kernel later loads the artifact on the AOT fast path (`WAFFLE_AOT=1`).
 */
final readonly class ContainerCompileCommand extends AbstractCommand
{
    private const string DEFAULT_ARTIFACT = 'var/cache/CompiledContainer.php';

    public function __construct(
        private ContainerInterface $container,
        private ContainerCompiler $compiler = new ContainerCompiler(),
        private string $namespace = 'Waffle\\Generated',
        private string $className = 'CompiledContainer',
        private string $runtimeContainer = ContainerCompiler::DEFAULT_RUNTIME_CONTAINER,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'container:compile';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Compiles the service container to a reflection-free artifact (AOT-01).';
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return 'container:compile [<artifact-path>]';
    }

    #[\Override]
    public function getHelp(): string
    {
        return (
            "Reads the booted, locked container's service definitions and emits a\n"
            . "generated CompiledContainer class with hardcoded constructor wiring\n"
            . "(default artifact: var/cache/CompiledContainer.php). The kernel loads\n"
            . 'it on the AOT fast path when WAFFLE_AOT=1, falling back to reflection otherwise.'
        );
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $artifactPath = $input->getArgument('artifact-path') ?? self::DEFAULT_ARTIFACT;

        $output->write(sprintf('Compiling container to "%s"… ', $artifactPath), Verbosity::NORMAL);

        try {
            $source = $this->compiler->compile(
                $this->container,
                $this->namespace,
                $this->className,
                $this->runtimeContainer,
            );

            $directory = dirname($artifactPath);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                $output->writeError(sprintf('Failed to create the artifact directory "%s".', $directory));
                return ExitCode::CONFIG->value;
            }

            if (file_put_contents($artifactPath, $source) === false) {
                $output->writeError(sprintf('Failed to write the container artifact to "%s".', $artifactPath));
                return ExitCode::CONFIG->value;
            }
        } catch (Throwable $e) {
            $output->writeError(sprintf('Container compilation failed: %s', $e->getMessage()));
            return ExitCode::FAILURE->value;
        }

        $output->writeLine(sprintf('done (%s\\%s).', $this->namespace, $this->className), Verbosity::NORMAL);
        return ExitCode::SUCCESS->value;
    }
}
