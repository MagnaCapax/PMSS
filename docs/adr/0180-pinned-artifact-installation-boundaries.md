# ADR 0180: Pinned artifact installation boundaries

Date: 2026-10-25
Category: architecture

## Status

Accepted for the operator-approved architectural simplification run.

## Context

The remote installer facade mixed executable refresh decisions with archive
extraction. qui, Scryer and slskd each owned the same architecture/version gate
and install/marker sequence. Two artifact callback APIs also used opposite
dry-run defaults, although they shared the same download and verification code.

## Options Considered

- Keep separate portable controllers and the two callback APIs: duplicated
  sequencing and implicit dry-run policy remain.
- Introduce an installer registry: broader machinery than these existing
  entrypoints need, with a risk of changing app execution order.
- Share the portable controller and use one artifact callback API, with archive
  policy separated from executable refresh decisions.

## Decision

Keep `remoteBinary.php` as the include surface. `remoteBinary/archive.php` owns
source archive validation/extraction and the shared portable install controller.
The existing per-app entrypoints keep their pins, URLs and autoload order.
Portable tar and zip retain their respective commands and path checks; they
share architecture and version gating, download, install status handling and
marker publication. No downloaded application is executed by this flow.

`pmssPinnedRemoteArtifactTempFileUse()` is the sole verified-download callback
API. Its default skips the callback in dry runs. Package and AI-tool callers
explicitly retain their historical dry-run callback behavior. Together with
the torrent-client venv caller, they supply their existing temporary prefixes,
download descriptions and checksum-message suffixes as options. The same
`finally` owns temporary artifact removal on every exit.

## Consequences

- Three portable lifecycle controllers become one; the second callback API is
  removed, including all callers. There are no legacy parallel implementations.
- Archive policy has one module, while checksum/version-based executable refresh
  stays in the facade and version-probe trust stays in `versionProbe.php`.
- The tar binary/temp checks and zip root/marker checks stay distinct. This
  refactor does not broaden or narrow either validation policy.
- `PortableInstallerCharacterizationTest` freezes pins, exact shell commands,
  guard order, skip behavior, failure boundaries and marker bytes with recording
  dependencies. Artifact tests retain cleanup, exception and dry-run coverage,
  and lock the specialized caller options.
- No live installer or dry run is executed on the control host. Static checks
  run locally; the publishing lane runs the development suite on testing machines.
