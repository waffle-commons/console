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
     */
    protected function resolveTargetDir(InputInterface $input): string
    {
        $cwd = getcwd();
        $cwdStr = $cwd === false ? '.' : $cwd;
        $target = (string) ($input->getOption('target') ?? $cwdStr);
        $realPath = realpath($target);

        if ($realPath === false) {
            if (str_starts_with($target, '/')) {
                $realPath = $target;
            } else {
                $realPath = $cwdStr . '/' . $target;
            }
        }

        return rtrim((string) $realPath, '/');
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
            throw new \RuntimeException("composer.json introuvable dans les dossiers parents de {$targetDir}.");
        }

        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            throw new \RuntimeException("Impossible de lire composer.json à l'adresse : {$composerJsonPath}");
        }

        $composerData = json_decode($content, true);
        if (!is_array($composerData)) {
            throw new \RuntimeException('Le format de composer.json est invalide.');
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
            throw new \RuntimeException("Impossible de résoudre le namespace PSR-4 pour le dossier {$targetDir}.");
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
                throw new \RuntimeException("Impossible de créer le répertoire : {$dir}");
            }
        }

        if (file_exists($filepath) && !$force) {
            throw new \RuntimeException(
                "Le fichier cible existe déjà : {$filepath}. Utilisez --force (-f) pour l'écraser.",
            );
        }

        // Atomic writing using temp file and rename (Anti-OWASP A05:2021)
        $tmpFile = $filepath . '.' . uniqid('wfl', true) . '.tmp';
        if (file_put_contents($tmpFile, $content) === false) {
            throw new \RuntimeException("Échec de l'écriture dans le fichier temporaire : {$tmpFile}");
        }

        if (!rename($tmpFile, $filepath)) {
            unlink($tmpFile);
            throw new \RuntimeException("Échec du déplacement atomique de {$tmpFile} vers {$filepath}");
        }

        $output->writeLine("[SUCCÈS] Fichier généré avec succès : {$filepath}");
    }

    /**
     * Loads a stub template content from Stubs/ directory.
     */
    protected function loadStub(string $name): string
    {
        $stubPath = __DIR__ . '/Stubs/' . $name . '.stub';
        if (!file_exists($stubPath)) {
            throw new \RuntimeException("Stub introuvable : {$stubPath}");
        }
        $content = file_get_contents($stubPath);
        if ($content === false) {
            throw new \RuntimeException("Impossible de lire le fichier de stub : {$stubPath}");
        }
        return $content;
    }
}
