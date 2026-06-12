<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Audit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static checker for SEC-03: bans naive `===` / `!==` on sensitive call sites.
 *
 * Mago has no custom-rule API and its built-in `no-insecure-comparison` rule only
 * fires on the narrow two-password shape, so this scanner closes the gap. It
 * tokenises PHP source (`token_get_all`) and flags an identity comparison only
 * when:
 *   - NEITHER operand is an allow-listed literal (`null`, `''`, a number, a
 *     boolean) — a comparison against a constant is a value check, never a
 *     two-secret timing comparison; AND
 *   - at least one operand is named like a secret (token, hmac, signature, csrf,
 *     key, nonce, …), matched on camelCase / snake_case word boundaries so
 *     `monkey` or `machine` never trip the `key` / `mac` words.
 *
 * The intent is to push such comparisons through {@see \hash_equals()}. Stateless
 * and side-effect-free (FrankenPHP worker rule): it holds no per-run state.
 */
final readonly class SensitiveComparisonScanner
{
    /**
     * Identifier words that mark an operand as carrying a secret.
     *
     * @var list<string>
     */
    private const array SENSITIVE_WORDS = [
        'token',
        'secret',
        'hmac',
        'signature',
        'csrf',
        'hash',
        'sid',
        'key',
        'nonce',
        'digest',
        'mac',
        'password',
        'passwd',
        'pwd',
        'apikey',
        'bearer',
        'salt',
        'credential',
        'signing',
    ];

    /**
     * Words that demote a sensitive-looking name to a public label/identifier:
     * `keyId`, `tokenType`, `signatureName` reference a secret but do not hold its
     * material, so comparing them with `===` is legitimate (e.g. a JWK `kid`).
     *
     * @var list<string>
     */
    private const array QUALIFIER_WORDS = [
        'id',
        'ids',
        'name',
        'names',
        'type',
        'types',
        'kind',
        'label',
        'alg',
        'algo',
        'algorithm',
        'header',
        'scheme',
        'index',
    ];

    /**
     * Token ids that terminate an operand while scanning outward from an operator.
     *
     * @var list<int>
     */
    private const array BOUNDARY_IDS = [
        T_IS_IDENTICAL,
        T_IS_NOT_IDENTICAL,
        T_IS_EQUAL,
        T_IS_NOT_EQUAL,
        T_BOOLEAN_AND,
        T_BOOLEAN_OR,
        T_LOGICAL_AND,
        T_LOGICAL_OR,
        T_RETURN,
        T_ECHO,
        T_DOUBLE_ARROW,
        T_COALESCE,
        T_OPEN_TAG,
        T_INLINE_HTML,
    ];

    /**
     * Token ids that carry no meaning for operand analysis.
     *
     * @var list<int>
     */
    private const array SKIP_IDS = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    private const int MAX_SCAN = 32;

    /**
     * Recursively scan every `*.php` file under a directory.
     *
     * @return list<SensitiveComparison>
     */
    public function scanDirectory(string $directory): array
    {
        $findings = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            foreach ($this->scanFile($fileInfo->getPathname()) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Scan a single file by path.
     *
     * @return list<SensitiveComparison>
     */
    public function scanFile(string $path): array
    {
        $code = file_get_contents($path);
        if ($code === false) {
            return [];
        }

        return $this->scanCode($code, $path);
    }

    /**
     * Scan a string of PHP source (filesystem-free; the unit-test entry point).
     *
     * @return list<SensitiveComparison>
     */
    public function scanCode(string $code, string $file): array
    {
        // `token_get_all()` is natively typed as a loose array (inherent mixed,
        // like `json_decode`): narrow the documented token shape once.
        /** @var list<string|array{0: int, 1: string, 2: int}> $tokens */
        $tokens = token_get_all($code);
        $lines = explode("\n", $code);
        $findings = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            $id = $token[0];
            if ($id !== T_IS_IDENTICAL && $id !== T_IS_NOT_IDENTICAL) {
                continue;
            }

            $leftIndex = $this->meaningfulIndex($tokens, $index - 1, -1);
            $rightIndex = $this->meaningfulIndex($tokens, $index + 1, 1);
            if ($leftIndex === null || $rightIndex === null) {
                continue;
            }

            // A literal operand makes this a value check, not a two-secret compare.
            if ($this->isLiteralToken($tokens[$leftIndex]) || $this->isLiteralToken($tokens[$rightIndex])) {
                continue;
            }

            if (
                !$this->operandIsSensitive($tokens, $index - 1, -1)
                && !$this->operandIsSensitive($tokens, $index + 1, 1)
            ) {
                continue;
            }

            $line = $token[2];
            $findings[] = new SensitiveComparison(
                $file,
                $line,
                $id === T_IS_IDENTICAL ? '===' : '!==',
                trim($lines[$line - 1] ?? ''),
            );
        }

        return $findings;
    }

    /**
     * Index of the next meaningful token from $start moving by $step, or null.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens
     */
    private function meaningfulIndex(array $tokens, int $start, int $step): ?int
    {
        for ($i = $start; array_key_exists($i, $tokens); $i += $step) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], self::SKIP_IDS, true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * Whether the operand starting adjacent to an operator contains a sensitive
     * identifier, scanning outward (with paren/bracket depth tracking) until a
     * statement or expression boundary.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens
     */
    private function operandIsSensitive(array $tokens, int $start, int $step): bool
    {
        $opening = $step > 0 ? ['(', '['] : [')', ']'];
        $closing = $step > 0 ? [')', ']'] : ['(', '['];
        $depth = 0;
        $scanned = 0;

        for ($i = $start; array_key_exists($i, $tokens) && $scanned < self::MAX_SCAN; $i += $step, ++$scanned) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if (in_array($token, $opening, true)) {
                    ++$depth;
                    continue;
                }

                if (in_array($token, $closing, true)) {
                    --$depth;
                    if ($depth < 0) {
                        break;
                    }

                    continue;
                }

                if ($depth <= 0 && in_array($token, [';', ',', '{', '}', '=', '.', ':', '?'], true)) {
                    break;
                }

                continue;
            }

            $id = $token[0];

            if ($depth <= 0 && in_array($id, self::BOUNDARY_IDS, true)) {
                break;
            }

            if (in_array($id, self::SKIP_IDS, true)) {
                continue;
            }

            if (($id === T_VARIABLE || $id === T_STRING) && $this->isSensitiveName($token[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a lexer token is a literal operand (null / bool / number / string).
     *
     * @param string|array{0: int, 1: string, 2: int} $lexeme
     */
    private function isLiteralToken(string|array $lexeme): bool
    {
        if (is_string($lexeme)) {
            return false;
        }

        $id = $lexeme[0];
        if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_LNUMBER || $id === T_DNUMBER) {
            return true;
        }

        return $id === T_STRING && in_array(strtolower($lexeme[1]), ['null', 'true', 'false'], true);
    }

    /**
     * Whether an identifier (camelCase or snake_case) carries a secret-bearing word.
     */
    private function isSensitiveName(string $name): bool
    {
        $name = ltrim($name, '$');
        if ($name === '') {
            return false;
        }

        // Break camelCase into words, then normalise separators to spaces.
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name) ?? $name;
        $spaced = str_replace(['_', '-'], ' ', $spaced);
        $words = preg_split('/\s+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return false;
        }

        $hasSecret = false;
        foreach ($words as $word) {
            if (in_array($word, self::QUALIFIER_WORDS, true)) {
                // A label/identifier ABOUT a secret (keyId, tokenType) — not the
                // material itself; never timing-sensitive.
                return false;
            }
            if (in_array($word, self::SENSITIVE_WORDS, true)) {
                $hasSecret = true;
            }
        }

        return $hasSecret;
    }
}
