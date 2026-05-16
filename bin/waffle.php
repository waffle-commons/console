<?php

declare(strict_types=1);

/**
 * Reference entry point for the `waffle` CLI.
 *
 * Skeleton-shipped projects ship their own bin/waffle.php that wires the
 * concrete cache + router + container; this file is a minimal reference
 * showing the expected boot shape.
 *
 * Usage:
 *   php vendor/bin/waffle <command> [options...]
 *   php vendor/bin/waffle list
 */

use Waffle\Commons\Console\ConsoleApplication;
use Waffle\Commons\Console\Output\StreamOutput;

require_once __DIR__ . '/../vendor/autoload.php';

$app = new ConsoleApplication(
    name: 'Waffle',
    version: '0.1.0-alpha6',
    output: new StreamOutput(),
);

// Concrete commands (CacheClearCommand, RouteListCommand, SecurityAuditCommand)
// require the framework's runtime dependencies (Cache, Router) which are NOT
// available in this stand-alone bin. Skeleton/workspace shadow this file with
// a project-specific entry that performs the bootstrap.

exit($app->run());
