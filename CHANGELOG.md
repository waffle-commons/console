# Changelog — waffle-commons/console

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [0.1.0-beta6] — 2026-08-22

**Theme: code-generation and AOT hardening.**

### Fixed
- Waffle Maker validates every interpolated CLI token against strict identifier/type grammars and runs `php -l` on generated source before the atomic write, closing a codegen-injection path (Beta6 audit FIX-01).
- The generated route cache rehydrates with `unserialize(..., ['allowed_classes' => [MatchedRoute::class]])` instead of an unrestricted call (defence-in-depth; no untrusted-input path reached it).
- The compiled container memoises **only inlined services**: passthrough singletons delegate to the runtime container instead of being memoised twice, so a `ResettableInterface` service resets exactly once per request in AOT mode as it does interpreted.

### Changed
- **Worker-safety audit coverage.** This component was never audited by `wfl igor`: it had no `igor-php/igor-php` in `require-dev`, so the ecosystem runner silently skipped it for four releases. It now ships `igor.json`, the `composer igor` script, and the dev dependency, and is part of the 0-KO gate.
- **Worker-safety annotations.** The CLI-only classes (`ConsoleApplication`, `ArgvInput`, `StreamOutput`, `NullOutput`, and the built-in commands) carry a class-level `#[WorkerSafe(scope: 'cli')]`: they are constructed per `bin/waffle` invocation and never enter the FrankenPHP worker container.

### Documentation
- The README now links into the central Diátaxis documentation tree (DOC-02).

## [0.1.0-beta5] — 2026-07-08

**Theme: context-aware voters & unpredictable temp names.**

### Added
- `Command\ContainerCompileCommand` (`container:compile`) + `Compiler\ContainerCompiler` — the AOT-01 service-container compiler. Reads the booted, locked runtime container's definition map and emits a generated `CompiledContainer` (default artifact `var/cache/CompiledContainer.php`) with hardcoded constructor wiring for inlinable definitions, delegating closures/pre-registered objects to the composed runtime container. The emitted graph is identical to the runtime container's; only the resolution mechanism (static calls instead of reflection) changes. Source is hand-assembled — no codegen dependency — to hold the contracts-only perimeter. `Exception\CompilerException` is raised when a graph cannot be compiled to static source.
- `Command\RouteCompileCommand` (`route:compile`) — the AOT-02 routing-table compiler. Serialises the router's priority-sorted `MatchedRoute` list to a build-time artifact (default `var/cache/routes.trie.php`) the router rehydrates at boot, skipping discovery on the hot path. An optional app-wired trie-builder closure may produce the concrete `RouteTrie` array; with none injected the command serialises the route list directly and the router rebuilds the trie at boot (mandatory fallback, identical behaviour either way).

### Changed
- Waffle Maker `make:voter`: the generated voter (`voter.stub`) and the `AllowAllVoter` test helper now implement the context-aware `decide(SecurityContextInterface $ctx, mixed $subject = null): bool` signature — the authenticated identity is reachable via `$ctx->getIdentity()` and `$subject` carries the resource under decision (still fail-closed by default). The stub now imports `Waffle\Commons\Contracts\Auth\SecurityContextInterface`.
- **DX-01** — `Maker\AbstractMakerCommand` now derives the atomic-write temp-file suffix from `bin2hex(random_bytes(6))` instead of `uniqid('wfl', true)`, removing the guessable temp path (Anti-OWASP A05:2021).
- `mago.toml`: the `cyclomatic-complexity` linter rule is now enabled with a ratchet `threshold = 50` (previously disabled).

## [0.1.0-beta4] — 2026-06-13

**Theme: timing-safety gate.**

### Added
- `Audit\SensitiveComparisonScanner` + `Command\SensitiveComparisonAuditCommand` (`security:compare-audit`) — a `token_get_all` scan that bans naive `===` / `!==` on secret/token/HMAC/signature operands which must use `hash_equals()` (SEC-03); also exposed monorepo-wide as `wfl compare-audit`.

### Changed
- Worker-safety migration to igor-php 0.7 (`#[WorkerSafe]`).

## [0.1.0-beta3] — 2026-06-07

**Theme: identity federation & stateless persistence (ecosystem wave).**

### Added
- `Waffle\Commons\Console\Command\MemoryAuditCommand` (`igor:audit`) — streams the monorepo-wide Igor memory-leak & state-mutation audit (`igor.sh`). Thin by design: it depends only on `Waffle\Commons\Contracts\Runtime\AuditRunnerInterface` (the `proc_open` engine lives in `waffle-commons/runtime`), so `console` gains no dependency edge. Returns `NO_INPUT` when the audit script is absent and `FAILURE` when Igor reports dangerous shared state. Distinct from `security:audit`, which audits ABAC/CSRF route coverage.
- `Waffle\Commons\Console\Command\DataWarmupCommand` (`data:warmup`) — pre-compiles registered SQR trees (and any other `DataWarmerInterface` artifact) into OPcache shared memory ahead of the first live request (Roadmap Beta-3 "CLI Route & Cache Warmup"). Depends only on the new `Contracts\Data\Warmup\DataWarmerInterface`; applications wire their concrete warmers in `bin/waffle`. Idempotent and strictly CLI-side.
- Waffle Maker: `make:entity` — immutable RFC-022 persistence entity with PHP 8.5 property-hook validation (`entity` stub) — and `make:repository` — stateless repository composing the worker-safe `SQLRepository` plus its `DataMapperInterface` mapper pair (`repository` / `repository_mapper` stubs, `--table` / `--identity` options, entity-suffix normalisation).

### Changed
- Lockstep version bump; `composer.lock` refreshed with the beta-3 dependency wave.

### Tests
- `MemoryAuditCommandTest` and the `FakeAuditRunner` helper added — cover `--local`/`-s` flag forwarding, the missing-script (`NO_INPUT`) path, and pass/fail exit mapping against a fake runner.
- `DataWarmupCommandTest` — empty registry, multi-warmer aggregation, "nothing to warm" reporting and failure exit mapping.
- `MakerCommandsTest` extended for `make:entity` (generated hooks + constructor) and `make:repository` (repository + mapper pair, `Repository`-suffix and `--table` defaults, metadata/edge cases).

## [0.1.0-beta2.1] — 2026-05-30

### Changed
- Lockstep re-tag of `0.1.0-beta2` (umbrella housekeeping patch) — no source changes in this component.

## [0.1.0-beta2] — 2026-05-29

### Changed
- Lockstep version bump; `composer.lock` refreshed to align with the ecosystem-wide dependency wave.

## [0.1.0-beta1]

See the umbrella [CHANGELOG](../CHANGELOG.md#010-beta1) for the full Beta-1 narrative — zero-magic CLI runtime with explicit command registration.
