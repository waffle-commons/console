<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Command;

use Waffle\Commons\Contracts\Console\Enum\ExitCode;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;
use Waffle\Commons\Contracts\Routing\RouterInterface;

/**
 * `waffle route:list` — prints the compiled routing table (RFC-012 §3.2).
 *
 * Outputs a fixed-width table: PATH · CONTROLLER · METHOD · NAME. Routes are
 * dumped verbatim from `RouterInterface::getRoutes()`; the command does no
 * filtering or sorting beyond ordered-by-insertion.
 */
final readonly class RouteListCommand extends AbstractCommand
{
    public function __construct(
        private RouterInterface $router,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'route:list';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Lists every route discovered by the router.';
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return 'route:list';
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = $this->router->getRoutes();

        if ($routes === []) {
            $output->writeLine('No routes registered.');
            return ExitCode::SUCCESS->value;
        }

        // Compute column widths for nicer alignment.
        $widths = ['path' => 4, 'classname' => 10, 'method' => 6, 'name' => 4];
        foreach ($routes as $route) {
            $widths['path'] = max($widths['path'], strlen($route->path));
            $widths['classname'] = max($widths['classname'], strlen($route->className));
            $widths['method'] = max($widths['method'], strlen($route->method));
            $widths['name'] = max($widths['name'], strlen($route->name));
        }

        $output->writeLine(sprintf(
            '%s  %s  %s  %s',
            str_pad('PATH', $widths['path']),
            str_pad('CONTROLLER', $widths['classname']),
            str_pad('METHOD', $widths['method']),
            str_pad('NAME', $widths['name']),
        ));

        foreach ($routes as $route) {
            $output->writeLine(sprintf(
                '%s  %s  %s  %s',
                str_pad($route->path, $widths['path']),
                str_pad($route->className, $widths['classname']),
                str_pad($route->method, $widths['method']),
                str_pad($route->name, $widths['name']),
            ));
        }

        $output->writeLine(sprintf('%d route(s).', count($routes)));
        return ExitCode::SUCCESS->value;
    }
}
