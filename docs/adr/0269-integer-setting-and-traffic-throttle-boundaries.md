# ADR 0269: Integer-setting and traffic-throttle boundaries

Date: 2026-11-11
Category: architecture

## Status
Accepted

## Context
Integer settings mixed value validation, two copies of verified-file parsing,
path construction and guarded publication. Traffic throttling mixed display-file
operations, runtime markers and recovery policy. The GiB command controller also
owned account lookup and home resolution. These helpers are shared by existing
provisioning commands and cron jobs; their include paths and results must remain
stable, including the distinction between missing and unreadable limits.

## Options Considered
- Keep the mixed modules and duplicate reading and cooldown logic.
- Introduce configurable storage or recovery objects, adding a second API.
- Retain existing includes and helpers, separate their responsibilities, and
  consolidate only equivalent reading and age decisions.

## Decision
Keep integerSetting.php as the storage/path facade. integerSettingValue.php owns
input policy; integerSettingRead.php owns verified reads and parsing. Both public
readers use one verified-read/trim/parse path. Only the nullable reader performs
the original clearstatcache/lstat preflight: absence returns its default, whereas
a present unreadable or invalid file returns null. The ordinary reader continues
to use its default for every unavailable value without adding a stat preflight.
Both retain the 256-byte bound, whitespace set, parser error reference and casts.
Missing input is classified within the existing unsupported-type branch, keeping
all acceptance rules and error strings.

Keep trafficLimitThrottle.php as the display-file facade. Marker lifecycle and
logging live in trafficLimitMarkers.php; recovery lives in trafficLimitReconcile.php.
Recovery selects the existing assertion source and checks its age once. Marker
absence still requires a valid readable display copy, and expired orphan recovery
still rechecks the marker. Equality with the cooldown is still unexpired. Lifting
still removes the marker first, logs, attempts removal twice with a one-second
pause and returns the second result. Apply returns directly on success, without
separate result and error-prefix bookkeeping.

trafficLimitCli.php retains bootstrap and command execution; trafficLimitAccount.php
owns the existing account lookup and guarded home resolver, including override hooks.
No caller migrates to a new interface, and no parallel implementation remains.

## Consequences
Five responsibility extractions keep the mixed modules below 150 lines. Two file
parsing paths become one, two cooldown checks become one, a separate missing-type
gate disappears, and apply no longer stores derived success/prefix state. Runtime
code lines decrease, excluding comments, whitespace and PHP tags; module headers
and documentation increase the physical line count.

IntegerSettingReadCharacterizationTest locks observations, defaults, casts, parser
inputs, exception/error identity and validation boundaries. TrafficLimitRecoveryCharacterizationTest
locks both cooldown sources, exact age boundaries, retry order and failure text.
TrafficLimitBoundaryCharacterizationTest locks marker write/mode order and account
refusal messages and exit codes. Existing CLI and guarded-writer tests retain their
entrypoints; source-location assertions follow moved functions. Tests run only on
testing machines; the devbox checks changed PHP syntax.

The existing file-type, owner, path and mode checks remain intact. This change
introduces no credential handling, service, listener or public endpoint. The
root-store/display-copy boundary of ADR 0213 remains unchanged.

The nullable integer-setting reader rejects empty or NUL-containing paths before
cache, metadata or content access. Such paths cannot establish file absence and
return the existing unreadable result (`null`), preserving the caller's current
throttle state rather than selecting the absent-file default. Valid missing paths
still return the supplied default; valid present paths retain their read/parse
order. IntegerSettingReadCharacterizationTest covers the malformed boundaries
with failing I/O stubs and retains the valid-path observation matrix.

## References
- [ADR 0065: Traffic limit storage and CLI boundaries](0065-traffic-limit-storage-and-cli-boundaries.md)
- [ADR 0213: Home files are display only](0213-home-directory-files-are-display-only-never-system-settings.md)
- [Architecture](../architecture.md)
- [Function contracts](../contracts.md)
