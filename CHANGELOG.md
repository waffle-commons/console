# Changelog — waffle-commons/console

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [Unreleased] — targeting `0.1.0-beta2`

### Added
- `Waffle\Commons\Console\Command\MemoryAuditCommand` (`igor:audit`) — streams the monorepo-wide Igor memory-leak & state-mutation audit (`igor.sh`). Thin by design: it depends only on `Waffle\Commons\Contracts\Runtime\AuditRunnerInterface` (the `proc_open` engine lives in `waffle-commons/runtime`), so `console` gains no dependency edge. Returns `NO_INPUT` when the audit script is absent and `FAILURE` when Igor reports dangerous shared state. Distinct from `security:audit`, which audits ABAC/CSRF route coverage.

### Changed
- Lockstep version bump; `composer.lock` refreshed to align with the ecosystem-wide dependency wave.

### Tests
- `MemoryAuditCommandTest` and the `FakeAuditRunner` helper added — cover `--local`/`-s` flag forwarding, the missing-script (`NO_INPUT`) path, and pass/fail exit mapping against a fake runner.

## [0.1.0-beta1]

See the umbrella [CHANGELOG](../CHANGELOG.md#010-beta1) for the full Beta-1 narrative — zero-magic CLI runtime with explicit command registration.
