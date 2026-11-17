# ADR 0295: Repository maintenance responsibility boundaries

Date: 2026-11-17
Category: architecture

## Status
Proposed

## Context

Repository refresh mixed prerequisite installation, source-line transformation,
template planning, publication and cache maintenance across two large modules.
Sonarr and Docker each tracked source changes separately from their transformed
lines. Cache cleaning constructed a default callback only to invoke it once.
The original include paths, public helpers and operational behavior are contracts.
MediaArea handling is frozen, and ADR 0008 preserves traditional Debian templates.

## Options Considered

- Keep both mixed modules: minimal movement, but retains unrelated responsibilities.
- Introduce a generic repository installer: would require policy adapters for
  different key migration, dry-run and publication behavior.
- Separate existing responsibilities behind the original includes and remove
  redundant state without introducing another installation flow.

## Decision

Choose the last option. `repositories.php` retains ordered refresh and the
unchanged MediaArea definition. Docker and Sonarr prerequisites move to
`repositoryDocker.php` and `repositorySonarr.php`. `apt.php` retains release
dispatch, `aptSources.php` owns discovery/planning/backed-up writes, and
`aptMaintenance.php` owns archived-release overrides and cache cleaning.
All existing public symbols remain; no parallel implementations remain.

Compare transformed line arrays directly, preserving source encounter order,
comment matching and each repository's existing newline policy. Sonarr's split
already preserves a terminal empty element, so its extra newline repair is
unreachable on the supported Debian runtime. Execute the default clean command
inside the same exception boundary as injected runners. Retain validation,
key-retirement gates, dry-run behavior, warning bytes and success-stamp policy.

## Consequences

- Existing includes continue to expose their public helpers; direct consumers
  need no migration. The template planner also becomes available from `apt.php`.
- Two change flags, one default callback adapter and one unreachable repair
  branch disappear. The six runtime files replace two mixed files without
  increasing runtime LOC; each remains at or below 150 lines.
- Four characterization test classes cover source bytes, operation order,
  publication failures, dry runs, cache execution and refresh exceptions using
  intercepted filesystem and command operations. They also load the unsplit
  baseline when the extracted modules are absent.
- No local execution rehearsal: devbox verification is changed-file `php -l`;
  the lane compares behavior on the testing machine before publication.

## Security Review

[APT CVE-2019-3462](https://security-tracker.debian.org/tracker/CVE-2019-3462)
documents HTTP redirect injection leading to command execution. Sonarr's
[authentication bypass advisory](https://github.com/Sonarr/Sonarr/security/advisories/GHSA-h5qx-5hjf-7c9r)
documents exposure through local-address authentication exceptions and proxy
header handling. These are reasons to preserve trust and exposure boundaries
while moving repository setup, rather than generalizing them into shared policy.

The stored values here are public signing keys, not primary or service
credentials. Disclosure reveals no account secret; substitution of a trusted key
could authorize malicious packages with host-wide root impact. The refactor
preserves key URLs, paths, fingerprints, scoped `signed-by` options, 0644 key
modes and existing diagnostic content. Source failures still retain the legacy
Sonarr key. No authentication, secret storage, listener, port or web endpoint
changes. Characterization assertions cover key/source modes, command bytes,
diagnostics and retirement order; execution verification remains external.

## References

- ADR 0008: Reject deb822 migration for Debian apt sources.
- `docs/security/operational-safety.md` and `docs/security/testing.md`.
- `RepositoryPrerequisitesCharacterizationTest`, `RepositoryDockerCharacterizationTest`,
  `AptRepositoryCharacterizationTest`, `RepositoryRefreshCharacterizationTest`.
