# ADR 0255: Torrent-client store convergence boundaries

Date: 2026-11-10
Category: architecture

## Status

Accepted for the operator-approved architectural simplification run.

## Context

The store controller mixed host measurements, installation dispatch and
convergence. The Deluge installer loaded that controller to obtain its directory
helper. Catalog loading and direct wheel installation duplicated the same wheel
name/pin rule. Store convergence, public projection and Deluge installation each
described daemon completeness separately.

## Options Considered

- Retain the duplicate checks and controller dependency: small local edits leave
  multiple definitions of the same artifact requirements.
- Introduce an artifact registry: adds machinery and risks changing existing
  client-specific policies.
- Keep the existing entrypoints, share their identical checks, and separate
  artifact installation from store convergence.

## Decision

`store.php` remains the convergence include. `storePaths.php` owns path/ownership
rules, host measurements, directory preparation and the shared presence check.
`storeInstall.php` owns the former installation callback and archive installer;
the old `storeArchive.php` implementation is relocated, with no parallel copy.
Deluge includes catalog/path helpers without loading the convergence controller.

The catalog owns one wheel name/pin validator used by both boundaries. Each
caller retains its own list, duplicate, compatibility and ownership checks.
The ordered wheel-path map also supplies duplicate detection during installation.
One presence check covers regular, non-symlink daemons and Deluge's required
web companion. Static checksum enforcement remains with store convergence;
projection still publishes present binaries without hashing them.

Convergence records successful installation once, then performs optional web
convergence and default-link publication in their existing order. Directory
guards, space reserve arithmetic, mirror order, commands, warning bytes, modes,
archive checks, cleanup and exception propagation retain their contracts.

## Consequences

- Two wheel validators become one; three daemon-completeness checks become one.
  The redundant wheel seen-set and duplicate successful-result branch disappear.
- Host/store helpers no longer require the controller. No new entry kind,
  configuration option, credential handling or service exposure is introduced.
- `TorrentClientsStoreBoundariesCharacterizationTest` locks presence versus pin
  policy, incomplete/link-backed Deluge pairs, wheel rejection and operation
  traces through the existing entrypoints. `TorrentClientsStoreInstallCharacterizationTest`
  locks backend dispatch, mirror exhaustion, pin normalization and throwables.
  Existing source-inspection fixtures follow the relocated code.
- Verification on the dev box is changed-file PHP syntax checks and reference
  searches. Tests and dry-run execution remain on the testing machine; no live
  installer or daemon is executed here.

## References

- [ADR 0105: Store foundation](0105-inert-torrent-client-store-foundation.md)
- [ADR 0180: Pinned artifact boundaries](0180-pinned-artifact-installation-boundaries.md)
- [ADR 0129: Curated Deluge entries](0129-curated-deluge-virtualenv-entries.md)
