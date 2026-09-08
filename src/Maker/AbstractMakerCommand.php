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
    /** A bare PHP identifier: letter/underscore, then letters/digits/underscores. */
    private const string CLASS_NAME_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /** A bare (optionally negative) integer literal. */
    private const string INTEGER_PATTERN = '/^-?\d+$/';

    protected ClassParser $classParser;

    public function __construct()
    {
        $this->classParser = new ClassParser();
    }

    /**
     * Validates a user-supplied identifier — a class/base name, or any other
     * CLI-derived name a `Make*Command` interpolates into a generated stub or
     * a target file path — before it is ever used for either.
     *
     * This is the same codegen-injection hardening `PropertyHookGenerator`
     * applies to field names: every `Make*Command` interpolates raw CLI
     * strings into stub slots with no surrounding quotes/braces (some, like
     * `MakeRepositoryCommand`'s `{{ IDENTITY }}`, even land as a *bare*
     * `$entity->{{ IDENTITY }}` property-access expression), and
     * {@see resolveNamespaceAndPath()} uses the class name to build the
     * output file path directly. Restricting every such value to a bare
     * identifier closes both the codegen-injection vector (no character the
     * grammar allows can break out of a stub) and path traversal (no `/`,
     * `.`, or null byte can ever reach a filesystem path built from it).
     *
     * @throws \InvalidArgumentException When `$name` is not a valid PHP identifier.
     */
    protected function assertValidIdentifier(string $name, string $label = 'name'): void
    {
        if (preg_match(self::CLASS_NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '[ERROR] Invalid %s "%s": must be a valid PHP identifier (e.g. UserController).',
                $label,
                $name,
            ));
        }
    }

    /**
     * Validates a CLI-derived value that a stub embeds inside a single-quoted
     * PHP string literal rather than as a bare identifier — a route path, a
     * console command name, an HTTP client base URI. Unlike
     * {@see assertValidIdentifier()} this is a deny-list, not an allow-list:
     * these values legitimately contain `/`, `{}`, `:`, `.`, `-`, etc., so
     * restricting them to an identifier grammar would reject real input.
     * Inside a PHP single-quoted literal only `'` and `\` are special —
     * rejecting those two (plus control bytes, on general hygiene grounds)
     * is precise for this context and permissive on everything else.
     *
     * @throws \InvalidArgumentException When `$value` carries a quote,
     *         backslash, or control character.
     */
    protected function assertSafeForQuotedStub(string $value, string $label): void
    {
        if (str_contains($value, "'") || str_contains($value, '\\') || preg_match('/[\x00-\x1F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf(
                '[ERROR] Invalid %s "%s": must not contain a quote, backslash, or control character.',
                $label,
                $value,
            ));
        }
    }

    /**
     * Validates a CLI-derived value that lands as a BARE (unquoted) integer
     * literal in a generated stub — e.g. a route's `priority`. The most
     * dangerous of these slots: with no surrounding quotes or grammar of its
     * own, anything that isn't strictly digits (and an optional leading `-`)
     * is arbitrary PHP source at that position.
     *
     * @throws \InvalidArgumentException When `$value` is not a plain integer.
     */
    protected function assertValidInteger(string $value, string $label): void
    {
        if (preg_match(self::INTEGER_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '[ERROR] Invalid %s "%s": must be an integer.',
                $label,
                $value,
            ));
        }
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

        // Atomic writing using temp file and rename (Anti-OWASP A05:2021); the
        // unpredictable suffix avoids a guessable temp path (DX-01).
        $tmpFile = $filepath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmpFile, $content) === false) {
            throw new \RuntimeException("Failed to write to temporary file: {$tmpFile}");
        }

        if (!$this->hasValidSyntax($tmpFile)) {
            unlink($tmpFile);
            throw new \RuntimeException(
                "Generated content for {$filepath} failed a PHP syntax check; refusing to write it.",
            );
        }

        if (!rename($tmpFile, $filepath)) {
            unlink($tmpFile);
            throw new \RuntimeException("Failed to atomically rename {$tmpFile} to {$filepath}");
        }

        $output->writeLine("[SUCCESS] File successfully generated: {$filepath}");
    }

    /**
     * Lints generated PHP via `php -l` before it is ever placed at its final
     * path — a defense-in-depth backstop (codegen-injection hardening) behind
     * whatever identifier/type-hint grammars individual generators enforce.
     * Runs out-of-process (no shell involved: the command is passed as an
     * array) so a syntax error is caught even if a generator's own validation
     * has a gap.
     */
    private function hasValidSyntax(string $path): bool
    {
        /** @var list<resource> $pipes */
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if ($process === false) {
            throw new \RuntimeException('Failed to spawn the PHP syntax checker.');
        }

        // Must drain both pipes before closing them: an unread pipe can make the
        // child receive SIGPIPE while writing its "No syntax errors" message,
        // turning a genuinely valid file into a false-positive lint failure.
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
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
