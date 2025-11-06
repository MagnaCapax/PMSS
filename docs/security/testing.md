# Security Testing Guidelines

Security for PMSS emphasizes defense-in-depth on a shared host.

## Negative Tests
- Invalid/edge inputs for parsers (spec parsing, config readers) must return safe defaults or explicit errors.
- File writes must fail safely (temp files, backups, atomic replace) and restore on error.
- Network-affecting helpers (iptables rendering, FireQOS config) must degrade to no-op rather than partial application.

## Hermeticity
- Development tests MUST NOT mutate the real filesystem or network. Use temp dirs and environment overrides (e.g., `PMSS_OS_RELEASE_PATH`, `PMSS_APT_SOURCES_PATH`).

## Production Probes (read-only)
- Validate presence/versions of binaries and services.
- Confirm repo sources and apt metadata hashes when safe.

## Checklists
- Inputs validated and sanitized.
- Paths normalized; no traversal.
- Commands routed through `runStep()` with logs and rc checks.
- Permissions set conservatively; no world-writable artifacts.

