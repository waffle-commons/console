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
use Waffle\Commons\Console\Maker\Command\MakeControllerCommand;
use Waffle\Commons\Console\Maker\Command\MakeDtoCommand;
use Waffle\Commons\Console\Maker\Command\MakeMiddlewareCommand;
use Waffle\Commons\Console\Maker\Command\MakeVoterCommand;
use Waffle\Commons\Console\Maker\Command\MakeHttpClientCommand;
use Waffle\Commons\Console\Maker\Command\MakeCommandCommand;
use Waffle\Commons\Console\Maker\Command\MakeEventPairCommand;

require_once __DIR__ . '/../vendor/autoload.php';

$app = new ConsoleApplication(
    name: 'Waffle',
    version: '0.1.0-alpha6',
    output: new StreamOutput(),
);

// Waffle Maker (RFC-020)
$app->add(new MakeControllerCommand());
$app->add(new MakeDtoCommand());
$app->add(new MakeMiddlewareCommand());
$app->add(new MakeVoterCommand());
$app->add(new MakeHttpClientCommand());
$app->add(new MakeCommandCommand());
$app->add(new MakeEventPairCommand());

exit($app->run());
