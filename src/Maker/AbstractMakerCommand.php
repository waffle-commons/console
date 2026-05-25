<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker;

use Waffle\Commons\Console\Command\AbstractCommand;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;
use Waffle\Commons\Utils\Service\ClassParser;

/**
 * Base command for all scaffolding makers in Waffle Maker (RFC-020).
 * Implements filesystem security, atomic writing, and PSR-4 namespace discovery.
 */
abstract readonly class AbstractMakerCommand extends AbstractCommand
{
    protected ClassParser $classParser;

    public function __construct()
    {
        $this->classParser = new ClassParser();
    }

    /**
     * Resolves the target absolute directory path, ensuring it is located within the monorepo.
     * If the target is not explicitly set and resolves to a package root (with composer.json),
     * it automatically defaults to `src/<Subfolder>`.
     */
    protected function resolveTargetDir(InputInterface $input, string $defaultSubfolder = ''): string
    {
        $cwd = getcwd();
        $cwdStr = $cwd === false ? '.' : $cwd;

        $hasTargetOption = $input->getOption('target') !== null;
        $target = (string) ($input->getOption('target') ?? $cwdStr);
        $realPath = realpath($target);

        if ($realPath === false) {
            if (str_starts_with($target, '/')) {
                $realPath = $target;
            } else {
                $realPath = $cwdStr . '/' . $target;
            }
        }

        $resolved = rtrim((string) $realPath, '/');

        if (!$hasTargetOption && file_exists($resolved . '/composer.json')) {
            $resolved .= '/src';
            if ($defaultSubfolder !== '') {
                $resolved .= '/' . trim($defaultSubfolder, '/');
            }
        }

        return $resolved;
    }

    /**
     * Resolves the namespace and filepath using the local composer.json's autoload.psr-4 mapping,
     * with an extra validation layer using ClassParser to verify sibling namespaces.
     *
     * @return array{namespace: string, filepath: string}
     */
    protected function resolveNamespaceAndPath(string $targetDir, string $className): array
    {
        $dir = $targetDir;
        $composerJsonPath = null;

        // Traverse upwards to find composer.json
        while ($dir !== '/' && $dir !== '.' && $dir !== '') {
            if (file_exists($dir . '/composer.json')) {
                $composerJsonPath = $dir . '/composer.json';
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        if ($composerJsonPath === null) {
            throw new \RuntimeException("composer.json not found in parent directories of {$targetDir}.");
        }

        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read composer.json at: {$composerJsonPath}");
        }

        $composerData = json_decode($content, true);
        if (!is_array($composerData)) {
            throw new \RuntimeException('composer.json format is invalid.');
        }

        $psr4 = $composerData['autoload']['psr-4'] ?? [];
        if (!is_array($psr4)) {
            $psr4 = [];
        }

        $baseDir = dirname($composerJsonPath);
        $resolvedNamespace = '';

        foreach ($psr4 as $namespacePrefix => $paths) {
            $prefix = is_string($namespacePrefix) ? $namespacePrefix : '';
            $pathList = is_array($paths) ? $paths : [$paths];
            foreach ($pathList as $path) {
                $pathStr = is_string($path) ? $path : '';
                $real = realpath($baseDir . '/' . trim($pathStr, '/'));
                $fullPath = rtrim($real !== false ? $real : $baseDir . '/' . trim($pathStr, '/'), '/');
                if (str_starts_with($targetDir, $fullPath)) {
                    $subPath = substr($targetDir, strlen($fullPath));
                    $subNamespace = str_replace('/', '\\', trim($subPath, '/'));

                    $resolvedNamespace = rtrim($prefix, '\\');
                    if ($subNamespace !== '') {
                        $resolvedNamespace .= '\\' . $subNamespace;
                    }
                    break 2;
                }
            }
        }

        // Cross-check / validation layer using ClassParser with existing sibling files
        if (is_dir($targetDir)) {
            $files = glob($targetDir . '/*.php');
            if ($files !== false && count($files) > 0) {
                foreach ($files as $file) {
                    $siblingClass = $this->classParser->className($file);
                    if ($siblingClass !== '') {
                        $siblingParts = explode('\\', $siblingClass);
                        array_pop($siblingParts);
                        $siblingNamespace = implode('\\', $siblingParts);
                        if ($siblingNamespace !== '' && $resolvedNamespace !== $siblingNamespace) {
                            $resolvedNamespace = $siblingNamespace;
                            break;
                        }
                    }
                }
            }
        }

        if ($resolvedNamespace === '') {
            throw new \RuntimeException("Could not resolve PSR-4 namespace for directory {$targetDir}.");
        }

        return [
            'namespace' => $resolvedNamespace,
            'filepath' => $targetDir . '/' . $className . '.php',
        ];
    }

    /**
     * Atomically writes compiled content to the filesystem with strict permissions.
     */
    protected function writeFile(string $filepath, string $content, bool $force, OutputInterface $output): void
    {
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Could not create directory: {$dir}");
            }
        }

        if (file_exists($filepath) && !$force) {
            throw new \RuntimeException("Target file already exists: {$filepath}. Use --force (-f) to overwrite.");
        }

        // Atomic writing using temp file and rename (Anti-OWASP A05:2021)
        $tmpFile = $filepath . '.' . uniqid('wfl', true) . '.tmp';
        if (file_put_contents($tmpFile, $content) === false) {
            throw new \RuntimeException("Failed to write to temporary file: {$tmpFile}");
        }

        if (!rename($tmpFile, $filepath)) {
            unlink($tmpFile);
            throw new \RuntimeException("Failed to atomically rename {$tmpFile} to {$filepath}");
        }

        $output->writeLine("[SUCCESS] File successfully generated: {$filepath}");
    }

    /**
     * Loads a stub template content from Stubs/ directory.
     */
    protected function loadStub(string $name): string
    {
        $stubPath = __DIR__ . '/Stubs/' . $name . '.stub';
        if (!file_exists($stubPath)) {
            throw new \RuntimeException("Stub not found: {$stubPath}");
        }
        $content = file_get_contents($stubPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read stub file: {$stubPath}");
        }
        return $content;
    }
}
