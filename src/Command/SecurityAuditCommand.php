<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use ReflectionClass;
use ReflectionMethod;
use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;
use Waffle\Commons\Contracts\Routing\RouterInterface;
use Waffle\Commons\Contracts\Security\Attribute\Voter;
use Waffle\Commons\Contracts\Security\Csrf\Attribute\RequiresCsrfToken;

/**
 * `waffle security:audit` — surfaces ABAC + CSRF coverage for every route (RFC-012 §3.2).
 *
 * Walks every discovered route, reflects the controller method, and reports:
 *   - whether `#[Voter]` attributes are present (class + method levels)
 *   - whether `#[RequiresCsrfToken]` is present (only relevant for mutating methods)
 *
 * Exit code rule:
 *   - SUCCESS (0) when every route is guarded by AT LEAST one `#[Voter]`.
 *   - USAGE  (64) when any route is unguarded — the CLI runner can fail CI on this.
 */
final readonly class SecurityAuditCommand extends AbstractCommand
{
    public function __construct(
        private RouterInterface $router,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'security:audit';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Audits ABAC and CSRF coverage for every registered route.';
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return 'security:audit';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = $this->router->getRoutes();

        if ($routes === []) {
            $output->writeLine('No routes registered. Nothing to audit.');
            return ExitCode::SUCCESS->value;
        }

        $unguarded = 0;
        $output->writeLine('Security audit:');
        $output->writeLine('');

        foreach ($routes as $route) {
            $voters = $this->collectVoters($route['classname'], $route['method']);
            $csrf = $this->collectCsrfRequirement($route['classname'], $route['method']);

            $voterLabel = $voters === [] ? 'NONE' : implode(', ', $voters);
            $csrfLabel = $csrf ?? '-';

            $output->writeLine(sprintf(
                '  %s  voters=[%s]  csrf=%s',
                $route['name'],
                $voterLabel,
                $csrfLabel,
            ));

            if ($voters === []) {
                $unguarded++;
                $output->writeError(sprintf(
                    'UNGUARDED ROUTE: %s (%s::%s) — no #[Voter] attribute.',
                    $route['name'],
                    $route['classname'],
                    $route['method'],
                ));
            }
        }

        $output->writeLine('');
        $output->writeLine(sprintf(
            '%d route(s) audited, %d unguarded.',
            count($routes),
            $unguarded,
        ));

        return $unguarded === 0 ? ExitCode::SUCCESS->value : ExitCode::USAGE->value;
    }

    /**
     * @param class-string $class
     * @return list<string> Short voter names (last namespace segment) for compactness.
     */
    private function collectVoters(string $class, string $method): array
    {
        if (!class_exists($class)) {
            return [];
        }
        $reflection = new ReflectionClass($class);

        $names = [];
        foreach ($reflection->getAttributes(Voter::class) as $attr) {
            $instance = $attr->newInstance();
            $names[] = $this->shortName($instance->name);
        }
        if ($reflection->hasMethod($method)) {
            $methodReflection = $reflection->getMethod($method);
            foreach ($methodReflection->getAttributes(Voter::class) as $attr) {
                $instance = $attr->newInstance();
                $names[] = $this->shortName($instance->name);
            }
        }
        return $names;
    }

    /**
     * @param class-string $class
     * @return string|null Token id when the route requires CSRF; null otherwise.
     */
    private function collectCsrfRequirement(string $class, string $method): ?string
    {
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->hasMethod($method)) {
            return null;
        }
        $methodReflection = $reflection->getMethod($method);
        $attributes = $methodReflection->getAttributes(RequiresCsrfToken::class);
        if ($attributes === []) {
            return null;
        }
        return $attributes[0]->newInstance()->id;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        $last = end($parts);
        return is_string($last) ? $last : $fqcn;
    }
}
