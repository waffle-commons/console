<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use Closure;
use Throwable;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\Enum\Verbosity;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;
use Waffle\Commons\Contracts\Routing\MatchedRoute;
use Waffle\Commons\Contracts\Routing\RouterInterface;

/**
 * `waffle route:compile` — serialises the discovered routing table to a build-time
 * cache artifact (AOT-02).
 *
 * The command boots the router (running attribute discovery via the existing
 * `RouteDiscoverer`/`RouteParser`), takes the priority-sorted `MatchedRoute` list
 * from {@see RouterInterface::getRoutes()}, and writes a PHP file the router
 * rehydrates at boot — skipping discovery entirely on the hot path.
 *
 * Perimeter note (RFC-019 / mago guard): this component depends only on the
 * routing **contracts**, never on the concrete `Waffle\Commons\Routing` package.
 * The concrete static-lookup-tree (`RouteTrie`) lives in routing, so producing
 * the trie array is delegated to an OPTIONAL build closure the application wires
 * (the app may reference `RouteTrie::build($routes)->toArray()`). When no closure
 * is injected the command falls back to serialising the route list itself — the
 * router rebuilds the trie from that list at boot, so behaviour is identical
 * either way (transparency + mandatory fallback).
 *
 * Default artifact path: `var/cache/routes.trie.php`. A positional argument
 * overrides it.
 */
final readonly class RouteCompileCommand extends AbstractCommand
{
    private const string DEFAULT_ARTIFACT = 'var/cache/routes.trie.php';

    /**
     * @param Closure(list<MatchedRoute>): array<string, mixed>|null $trieCompiler
     *        Optional app-wired builder turning the route list into the trie's
     *        serialised array; null serialises the route list directly.
     */
    public function __construct(
        private RouterInterface $router,
        private ?Closure $trieCompiler = null,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'route:compile';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Compiles the routing table to a build-time cache artifact (AOT-02).';
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return 'route:compile [<artifact-path>]';
    }

    #[\Override]
    public function getHelp(): string
    {
        return (
            "Discovers every #[Route] via the router, then serialises the resulting\n"
            . "static lookup tree to a PHP artifact (default: var/cache/routes.trie.php)\n"
            . 'that the router rehydrates at boot to avoid runtime discovery.'
        );
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $artifactPath = $input->getArgument('artifact-path') ?? self::DEFAULT_ARTIFACT;

        $output->write(sprintf('Compiling routes to "%s"… ', $artifactPath), Verbosity::NORMAL);

        try {
            $routes = $this->router->getRoutes();
            $payload = $this->trieCompiler !== null ? ($this->trieCompiler)($routes) : $routes;

            if (!$this->writeArtifact($artifactPath, $payload)) {
                $output->writeError(sprintf('Failed to write the route artifact to "%s".', $artifactPath));
                return ExitCode::CONFIG->value;
            }
        } catch (Throwable $e) {
            $output->writeError(sprintf('Route compilation failed: %s', $e->getMessage()));
            return ExitCode::FAILURE->value;
        }

        $output->writeLine(sprintf('done (%d route(s)).', count($routes)), Verbosity::NORMAL);
        return ExitCode::SUCCESS->value;
    }

    /**
     * Writes a PHP artifact that `return`s the rehydrated payload. The payload is
     * serialised (not `var_export()`ed) so the immutable `MatchedRoute` DTOs
     * round-trip exactly without needing a `__set_state()` hook.
     *
     * SEC-02 hardening: the emitted `unserialize()` call is scoped to
     * `allowed_classes: [MatchedRoute::class]`. `$payload` is build-time-only
     * (sourced from `RouterInterface::getRoutes()`, a trusted producer — see
     * {@see MatchedRoute}'s own docblock) and `MatchedRoute` carries no magic
     * methods, so this is defense-in-depth rather than a live exploit fix; it
     * costs nothing and matches the discipline every sibling `unserialize()`
     * consumer in this codebase already follows.
     *
     * @param list<MatchedRoute>|array<string, mixed> $payload
     */
    private function writeArtifact(string $path, array $payload): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return false;
        }

        $encoded = base64_encode(serialize($payload));
        $source =
            "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "// Generated by `waffle route:compile` (AOT-02). Do not edit by hand.\n"
            . "return \\unserialize(\\base64_decode('{$encoded}'), ['allowed_classes' => [\\"
            . MatchedRoute::class
            . "::class]]);\n";

        return file_put_contents($path, $source) !== false;
    }
}
