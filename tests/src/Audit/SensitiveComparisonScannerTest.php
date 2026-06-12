<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Console\Audit\SensitiveComparison;
use Waffle\Commons\Console\Audit\SensitiveComparisonScanner;
use WaffleTests\Commons\Console\AbstractTestCase;

#[CoversClass(SensitiveComparisonScanner::class)]
#[CoversClass(SensitiveComparison::class)]
final class SensitiveComparisonScannerTest extends AbstractTestCase
{
    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = APP_ROOT . '/var/compare-audit-test-' . bin2hex(random_bytes(4));
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0o755, true);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $files = glob($this->dir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function comparisons(): iterable
    {
        // [snippet expression, expected to be flagged]
        yield 'two secret variables' => ['$expectedHmac === $providedHmac', true];
        yield 'not-identical on csrf' => ['$csrfToken !== $submittedToken', true];
        yield 'property vs variable' => ['$this->signature === $candidate', true];
        yield 'method result vs secret' => ['$this->computeMac($p) === $providedMac', true];
        yield 'apiKey camelCase' => ['$apiKey === $providedKey', true];
        yield 'snake_case secret' => ['$session_key === $expected_key', true];

        yield 'secret vs empty string' => ["\$token === ''", false];
        yield 'secret vs null' => ['$signature === null', false];
        yield 'secret vs bool' => ['$valid === true', false];
        yield 'length check number' => ['strlen($token) === 32', false];
        yield 'non-sensitive pair' => ['$count === $total', false];
        yield 'monkey is not a key' => ['$monkeyCount === $other', false];
        yield 'token type vs scheme literal' => ["\$tokenType === 'bearer'", false];

        // Identifiers/labels ABOUT a secret are public (JWK kid, token type, …).
        yield 'jwk key id is public' => ['$jwkKeyId !== $keyId', false];
        yield 'token type variable' => ['$tokenType === $expectedType', false];
        yield 'signature name label' => ['$signatureName === $other', false];
    }

    #[DataProvider('comparisons')]
    public function testHeuristic(string $expression, bool $flagged): void
    {
        $findings = $this->scan("<?php\n\$result = " . $expression . ";\n");

        self::assertCount($flagged ? 1 : 0, $findings);
    }

    public function testHashEqualsUsageIsNeverFlagged(): void
    {
        self::assertSame([], $this->scan("<?php\nif (hash_equals(\$expectedHmac, \$providedHmac)) {\n}\n"));
    }

    public function testCapturesOperatorLineAndSnippet(): void
    {
        $findings = $this->scan("<?php\n\n\$ok = \$expectedToken === \$providedToken;\n");

        self::assertCount(1, $findings);
        $finding = $this->first($findings);
        self::assertSame('===', $finding->operator);
        self::assertSame(3, $finding->line);
        self::assertSame('$ok = $expectedToken === $providedToken;', $finding->snippet);
        self::assertSame('inline.php', $finding->file);
    }

    public function testNotIdenticalOperatorIsLabelled(): void
    {
        $findings = $this->scan("<?php\n\$ok = \$apiKey !== \$givenKey;\n");

        self::assertCount(1, $findings);
        self::assertSame('!==', $this->first($findings)->operator);
    }

    public function testMultipleFindingsAreAllReported(): void
    {
        $code = "<?php\n\$a = \$hmacOne === \$hmacTwo;\n\$b = \$tokenOne !== \$tokenTwo;\n";

        self::assertCount(2, $this->scan($code));
    }

    public function testScanFileReadsFromDisk(): void
    {
        $path = $this->dir . '/Violating.php';
        file_put_contents($path, "<?php\n\$ok = \$storedSecret === \$givenSecret;\n");

        $findings = new SensitiveComparisonScanner()->scanFile($path);

        self::assertCount(1, $findings);
        self::assertSame($path, $this->first($findings)->file);
    }

    public function testScanDirectoryWalksPhpFilesOnly(): void
    {
        file_put_contents($this->dir . '/Clean.php', "<?php\nif (hash_equals(\$a, \$b)) {\n}\n");
        file_put_contents($this->dir . '/Bad.php', "<?php\n\$ok = \$expectedMac === \$actualMac;\n");
        file_put_contents($this->dir . '/notes.txt', '$secret === $other');

        $findings = new SensitiveComparisonScanner()->scanDirectory($this->dir);

        self::assertCount(1, $findings);
        self::assertStringEndsWith('Bad.php', $this->first($findings)->file);
    }

    /**
     * @return list<SensitiveComparison>
     */
    private function scan(string $code): array
    {
        return new SensitiveComparisonScanner()->scanCode($code, 'inline.php');
    }

    /**
     * @param list<SensitiveComparison> $findings
     */
    private function first(array $findings): SensitiveComparison
    {
        return $findings[0] ?? self::fail('expected at least one finding');
    }
}
