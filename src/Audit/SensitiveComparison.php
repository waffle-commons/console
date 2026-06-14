<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Audit;

/**
 * A single naive `===` / `!==` comparison flagged on a sensitive call site (SEC-03).
 *
 * Immutable record produced by {@see SensitiveComparisonScanner}: it pinpoints a
 * timing-unsafe identity comparison between two runtime values where at least one
 * operand is named like a secret (token, hmac, signature, …) and should instead go
 * through {@see \hash_equals()}.
 */
final readonly class SensitiveComparison
{
    public function __construct(
        public private(set) string $file,
        public private(set) int $line,
        public private(set) string $operator,
        public private(set) string $snippet,
    ) {}
}
