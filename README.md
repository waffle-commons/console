[![Discord](https://img.shields.io/discord/755288001592033391?logo=discord)](https://discord.gg/eKgywnfXr2)
[![PHP Version Require](http://poser.pugx.org/waffle-commons/console/require/php)](https://packagist.org/packages/waffle-commons/console)
[![PHP CI](https://github.com/waffle-commons/console/actions/workflows/main.yml/badge.svg)](https://github.com/waffle-commons/console/actions/workflows/main.yml)
[![codecov](https://codecov.io/gh/waffle-commons/console/graph/badge.svg?token=d74ac62a-7872-4035-8b8b-bcc3af1991e0)](https://codecov.io/gh/waffle-commons/console)
[![Latest Stable Version](http://poser.pugx.org/waffle-commons/console/v)](https://packagist.org/packages/waffle-commons/console)
[![Latest Unstable Version](http://poser.pugx.org/waffle-commons/console/v/unstable)](https://packagist.org/packages/waffle-commons/console)
[![Total Downloads](https://img.shields.io/packagist/dt/waffle-commons/console.svg)](https://packagist.org/packages/waffle-commons/console)
[![Packagist License](https://img.shields.io/packagist/l/waffle-commons/console)](https://github.com/waffle-commons/console/blob/main/LICENSE.md)

Waffle Console Component
========================

> **Release:** `0.1.0-beta4` &nbsp;|&nbsp; [`CHANGELOG.md`](./CHANGELOG.md)

A minimalist, zero-magic CLI runtime for the Waffle Framework (RFC-012). Commands are registered **explicitly** at boot — no auto-discovery — and resolve their dependencies through constructor injection.

## 📦 Installation

```bash
composer require waffle-commons/console
```

## 🧱 Surface

| Class | Role |
| :--- | :--- |
| `Waffle\Commons\Console\ConsoleApplication` | `final` implementation of `ConsoleApplicationInterface`. Owns the command registry and the run loop. |
| `Waffle\Commons\Console\Command\AbstractCommand` | Base class — implements `CommandInterface` with shared helpers. |
| `Waffle\Commons\Console\Command\CacheClearCommand` | `cache:clear` — flushes the configured `CacheInterface` backend. |
| `Waffle\Commons\Console\Command\RouteListCommand` | `route:list` — renders the compiled route table. |
| `Waffle\Commons\Console\Command\SecurityAuditCommand` | `security:audit` — walks controllers and prints the resolved access ladder (`#[Rule]` / `#[Voter]`). |
| `Waffle\Commons\Console\Command\MigrateCommand` | `db:migrate` — applies pending SQL migrations through the contracts `MigrationRunnerInterface`, then resets the pool. |
| `Waffle\Commons\Console\Command\MemoryAuditCommand` | `igor:audit` — streams the monorepo-wide Igor memory-leak audit through the contracts `AuditRunnerInterface`. |
| `Waffle\Commons\Console\Command\DataWarmupCommand` | `data:warmup` — pre-compiles SQR trees / routing tables into OPcache shared memory through the contracts `DataWarmerInterface` (Beta-3). |
| `Waffle\Commons\Console\Maker\Command\Make*Command` | The nine Waffle Maker scaffolders (RFC-020): `make:controller`, `make:dto`, `make:entity`, `make:repository`, `make:middleware`, `make:voter`, `make:command`, `make:http-client`, `make:event-pair`. |
| `Waffle\Commons\Console\Input\ArgvInput` | `InputInterface` implementation parsing `argv`. |
| `Waffle\Commons\Console\Output\StreamOutput` | Default `OutputInterface` writing to `STDOUT` / `STDERR`. |
| `Waffle\Commons\Console\Output\NullOutput` | Silent `OutputInterface` for tests / quiet runs. |
| `Waffle\Commons\Console\Exception\ConsoleException` | Base exception (implements `ConsoleExceptionInterface`). |
| `Waffle\Commons\Console\Exception\CommandNotFoundException` | Thrown when `find($name)` cannot resolve. |
| `Waffle\Commons\Console\Exception\InvalidArgumentException` | Thrown on invalid CLI argument shape. |

## 🚀 Quick start

The exact signature of `ConsoleApplication::__construct`, verbatim from `src/ConsoleApplication.php`:

```php
public function __construct(
    private readonly string $name = Constant::DEFAULT_APP_NAME,
    private readonly string $version = '0.0.0',
    private readonly OutputInterface $output = new StreamOutput(),
    ?array $argv = null, // null → reads $_SERVER['argv']
) { /* … */ }
```

And the run loop:

```php
use Waffle\Commons\Console\ConsoleApplication;
use Waffle\Commons\Console\Command\CacheClearCommand;
use Waffle\Commons\Console\Command\RouteListCommand;

$app = new ConsoleApplication(name: 'Waffle', version: '0.1.0-beta0');

$app->add(new CacheClearCommand($cache));
$app->add(new RouteListCommand($router));

exit($app->run()); // argv read from constructor, returns int exit code
```

## 🪜 Public API

```php
final class ConsoleApplication implements ConsoleApplicationInterface
{
    public function getName(): string;
    public function getVersion(): string;
    public function add(CommandInterface $command): void;
    public function has(string $name): bool;
    public function find(string $name): CommandInterface;  // throws CommandNotFoundException
    public function all(): array;
    public function run(): int;                            // returns ExitCode::*->value
}
```

`run()`:

1. With no arguments, prints the available-commands listing and exits with `ExitCode::USAGE`.
2. The built-in `list` command name reprints the same listing with `ExitCode::SUCCESS`.
3. `-v` / `-vv` / `-vvv` / `--verbose` / `--very-verbose` / `--debug` / `--quiet` flags adjust output verbosity via `OutputInterface::setVerbosity(Verbosity)`.
4. Dispatches to the resolved command's `execute(InputInterface, OutputInterface): int`.
5. `ConsoleExceptionInterface` and any other `Throwable` are caught and returned as `ExitCode::FAILURE`, with the message printed to stderr.

## 🐘 PHP 8.5 features used

- `final class ConsoleApplication`.
- Constructor property promotion + `private readonly` on every dependency.
- Default `OutputInterface $output = new StreamOutput()` — PHP 8.1 `new in initializers`.
- `enum ExitCode: int` and `enum Verbosity` (from contracts) for typed exit codes / verbosity levels.
- Typed constants via `Waffle\Commons\Contracts\Console\Constant`.

## 🧭 Architectural boundary (`mago guard`)

An active dependency **perimeter** is enforced on every CI run by `vendor/bin/mago guard` (bundled into `composer mago`; zero baselines). The rules live in [`mago.toml`](./mago.toml) under `[guard.perimeter]` — a forbidden `use` statement fails the build, not a reviewer.

Production code under `Waffle\Commons\Console` may depend **only** on:

- `Waffle\Commons\Console\**` — itself
- `Waffle\Commons\Contracts\**` — the shared contracts package, the **only** Waffle dependency permitted
- `Psr\**` — PSR interfaces
- `@global` + `Psl\**` — PHP core and the PHP Standard Library

Test code under `WaffleTests\Commons\Console` is unrestricted (`@all`). Structural rules are guarded too: interfaces must be named `*Interface`, `Exception\**` classes must end in `*Exception`, and any `Enum\**` namespace may hold only `enum` declarations.

Contract-first, component-agnostic by construction: components compose through `waffle-commons/contracts`, never directly through one another.

## 🧪 Testing

```bash
docker exec -w /waffle-commons/console waffle-dev composer tests
```

## 📄 License

MIT — see [LICENSE.md](./LICENSE.md).
