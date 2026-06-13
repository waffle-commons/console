# Changelog — waffle-commons/console

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

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
