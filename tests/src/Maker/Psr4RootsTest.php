<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Maker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Waffle\Commons\Console\Maker\Psr4Roots;

#[CoversClass(Psr4Roots::class)]
final class Psr4RootsTest extends TestCase
{
    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = dirname(__DIR__, 3) . '/var/psr4-roots-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/src/Controller', 0o755, true);
        mkdir($this->dir . '/lib', 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->dir . '/composer.json')) {
            unlink($this->dir . '/composer.json');
        }

        rmdir($this->dir . '/src/Controller');
        rmdir($this->dir . '/src');
        rmdir($this->dir . '/lib');
        rmdir($this->dir);
    }

    public function testATargetUnderARootTakesItsPrefixAndItsSubPath(): void
    {
        $roots = $this->rootsOf(<<<'JSON'
            {"autoload": {"psr-4": {"App\\": "src/"}}}
            JSON);

        static::assertSame('App\\Controller', $roots->namespaceOf($this->dir . '/src/Controller'));
        static::assertSame('App', $roots->namespaceOf($this->dir . '/src'));
    }

    public function testTheFirstRootHoldingTheTargetWinsAndOnlyStringsAreRead(): void
    {
        $roots = $this->rootsOf(<<<'JSON'
            {"autoload": {"psr-4": {"Lib\\": ["lib/"], "7": "src/", "Bad\\": [3], "App\\": ["other/", "src/"]}}}
            JSON);

        static::assertSame('App\\Controller', $roots->namespaceOf($this->dir . '/src/Controller'));
    }

    public function testATargetNoRootHoldsHasNoNamespace(): void
    {
        $roots = $this->rootsOf(<<<'JSON'
            {"autoload": {"psr-4": {"Lib\\": "lib/"}}}
            JSON);

        static::assertSame('', $roots->namespaceOf($this->dir . '/src/Controller'));
    }

    public function testAComposerJsonWithoutAPsr4MapHasNoRoot(): void
    {
        $roots = $this->rootsOf('{"name": "acme/app"}');

        static::assertSame('', $roots->namespaceOf($this->dir . '/src'));
    }

    public function testAComposerJsonThatIsNotAnObjectIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.json format is invalid.');

        $this->rootsOf('"a string"');
    }

    private function rootsOf(string $json): Psr4Roots
    {
        file_put_contents($this->dir . '/composer.json', $json);

        return Psr4Roots::of($this->dir . '/composer.json');
    }
}
