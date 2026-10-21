<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker;

/**
 * The PSR-4 roots of a composer.json (`autoload.psr-4`): each namespace prefix with the directory it maps to,
 * in the order the file lists them. The decoded JSON is kept in one-element lists and narrowed in place,
 * never bound untyped; a prefix or a path that is not a string is skipped.
 */
final readonly class Psr4Roots
{
    /**
     * @param list<array{prefix: string, path: string}> $roots Paths as composer.json writes them, relative to `$baseDir`.
     */
    private function __construct(
        private string $baseDir,
        private array $roots,
    ) {}

    /**
     * @throws \RuntimeException When the file cannot be read or does not hold a JSON object.
     */
    public static function of(string $composerJsonPath): self
    {
        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read composer.json at: {$composerJsonPath}");
        }

        // json_decode() returns its result untyped: it is kept in a one-element
        // list and narrowed in place, never bound on its own.
        $decoded = [json_decode($content, true)];
        if (!is_array($decoded[0])) {
            throw new \RuntimeException('composer.json format is invalid.');
        }

        $map = is_array($decoded[0]['autoload'] ?? null) && is_array($decoded[0]['autoload']['psr-4'] ?? null)
            ? $decoded[0]['autoload']['psr-4']
            : [];

        $roots = [];
        foreach (array_keys($map) as $prefix) {
            $entry = [$map[$prefix] ?? null];
            $paths = is_array($entry[0]) ? $entry[0] : $entry;
            foreach (array_keys($paths) as $index) {
                $path = [$paths[$index] ?? null];
                if (is_string($prefix) && is_string($path[0])) {
                    $roots[] = ['prefix' => $prefix, 'path' => $path[0]];
                }
            }
        }

        return new self(dirname($composerJsonPath), $roots);
    }

    /**
     * The namespace of `$targetDir` under the first root that contains it, or `''` when none does.
     */
    public function namespaceOf(string $targetDir): string
    {
        foreach ($this->roots as $root) {
            $relative = trim($root['path'], '/');
            $real = realpath($this->baseDir . '/' . $relative);
            $rootDir = rtrim($real !== false ? $real : $this->baseDir . '/' . $relative, '/');
            if (!str_starts_with($targetDir, $rootDir)) {
                continue;
            }

            $namespace = rtrim($root['prefix'], '\\');
            $subNamespace = str_replace('/', '\\', trim(substr($targetDir, strlen($rootDir)), '/'));

            return $subNamespace === '' ? $namespace : $namespace . '\\' . $subNamespace;
        }

        return '';
    }
}
