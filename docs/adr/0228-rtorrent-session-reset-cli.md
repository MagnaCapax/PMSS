# ADR 0228: On-demand rTorrent session reset

Date: 2026-11-04
Category: architecture

## Status
Accepted

## Context
The watchdog's existing session-reset helper has no operator entry point.
An executor that survives repeated child crashes does not enter the automatic
reset branch, leaving no direct way to request the same recovery (#1058).

## Options Considered
- Broaden automatic escalation: affects every account and needs separate evidence.
- Reimplement session recovery in a CLI: duplicates path and ownership policy.
- Expose the existing helper under the account identity: narrow and reusable.

## Decision
Add `scripts/util/rtorrentSessionReset.php USERNAME`, invoked as root for one
validated account with a positive UID and its canonical home. Load all trusted
dependencies before permanently dropping supplementary groups, GID and UID,
following ADR 0121 and the existing key-update CLI pattern. Fail closed when
POSIX support or an identity transition is unavailable. Call the unchanged
`rtorrentProcessResetSessionDirectory()` and preserve its return result.

The helper retains its path/symlink refusals, quarantine rename, empty-session
creation and owner/group restoration. Diagnostics, including the backup path,
reach stderr; completion reaches stdout. Operators arrange a quiescent recovery
window and restart separately. This command neither signals processes nor
changes automatic escalation or ADR 0215's automatic environmental preflight.

## Consequences
- Recovery is explicit and scoped to one account; quarantined state is retained.
- Account permissions bound filesystem effects even if an account races paths.
  The command does not repair root-owned session files it cannot move as the account.
- No credentials are read, stored or logged. There are no new binds, ports or
  web endpoints. Existing session mode 0755 and account ownership are unchanged.
- Security review considered ruTorrent's symlink jail escape, CVE-2026-121546,
  and the upstream warning that RPC access grants account-level command execution.
  The CLI retains path guards, runs file work as the account and never loads
  account-controlled code; it introduces no credential value to expose.
- Hermetic CLI fixtures cover invalid requests, identity failures, call ordering
  and recovery outcomes. Existing helper tests cover quarantine and path guards.
  No live reset or dry run is performed on the build host; behavior tests run
  on the testing machine and in CI.

## References
- Refs #1058
- [Account-controlled objects](0121-root-operations-and-account-controlled-objects.md)
- [Automatic recovery preflight](0215-rtorrent-session-reset-environment-preflight.md)
- [Script contracts](../contracts.md#utilities-script-contracts)
- [CVE-2026-121546 record](https://github.com/CVEProject/cvelistV5/blob/main/cves/2026/121xxx/CVE-2026-121546.json)
- [Upstream RPC security guidance](https://github.com/rakshasa/rtorrent/wiki/RPC-Setup-XMLRPC)
