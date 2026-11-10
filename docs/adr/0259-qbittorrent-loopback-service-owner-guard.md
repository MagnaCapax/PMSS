# ADR 0259: qBittorrent loopback service owner guard

Date: 2026-11-10
Category: security

## Status

Accepted. Default off; a host is enabled only by the marker below, after the
testing-machine checks under Consequences.

## Context

PMSS runs an account's native qBittorrent WebUI on a loopback TCP port.
The monitoring OUTPUT rules currently accept `127.0.0.0/8` before per-UID
accounting. The requested optional policy is that loopback service ports accept
connections only from the owning account, with a root exemption for operator
tools. Existing hosts must retain their current rules until explicitly enabled.

ADR 0213 makes home-directory port files display copies, not firewall inputs.
Native reservations are `qbittorrent-<user>` in the root-owned
`/etc/seedbox/runtime/ports/` registry, resolved by
`pmssPortManagerReservationPath()`. The native watchdog resolves that reservation
in `user/torrentPort.php::pmssQbittorrentWatchdogPortResolve()`, then pins it
through `qbittorrentWatchdog.php` into `pmssQbittorrentPortEnsure()`, which writes
the actual `WebUI\Port` setting. This is the native WebUI listener port.

Docker uses a different reservation: `media-stack-qbittorrent-<user>`.
`mediaStackPorts.php::pmssMediaStackPortsEnsure()` reserves it and publishes
the customer installer display copy. `dockerInstallLsioTorrent.php` reads that
copy as the account; `dockerInstallLsio.php` publishes the host port on
`127.0.0.1` to container port 8080. This host port is a forwarder to the Docker
WebUI, separate from the native listener. `lighttpd/userConfigApply.php` selects
the running Docker publish before falling back to the native proxy port.
`template.lighttpd` sets `server.username` to the account: the host connection
to either endpoint uses that UID. Rootless Docker is launched through
`userDockerRunAs()` as the account; forwarding into the container happens in
its network namespace. No additional host UID exemption is needed for that
second leg. ADR 0112 documents the separate native and Docker reservations.
These are source-traced contracts, not observations of a running host.

## Options Considered

- Keep the existing policy only: cannot supply the requested opt-in setting.
- Guard only the native registry port: omits the separate Docker WebUI endpoint.
- Guard both root registry reservations: selected; keeps one renderer and
  preserves the owner's native and Docker panel connections.
- Resolve firewall ports from home files or Docker inspect: unnecessary;
  root-owned reservations already identify both managed service endpoints.

## Decision

Use `/etc/seedbox/config/qbittorrentLoopbackOwnerGuard.enabled` as an operator
marker, following the default-off host settings in ADRs 0068 and 0102.
The caller reads it and passes both the boolean and an account-to-ports map
to the pure `networkMonitoringRulesRender()` function. When absent, the caller
does not read reservations and the renderer returns byte-identical legacy rules.

When enabled, prepend one OUTPUT rule per registered WebUI port, matching
`-o lo -p tcp --syn --dport P -m owner ! --uid-owner U -m owner ! --uid-owner 0`
and using `REJECT --reject-with tcp-reset`. Both negated owner matches must
hold, preserving the owning account and root. Only the connection-opening SYN
is matched: it always carries its socket's owner, while kernel-sent packets
without an owning socket (TIME_WAIT ACKs, resets) would otherwise match both
negations. These rules precede the existing
loopback ACCEPT and all per-account accounting rules. No application settings,
IPv6 policy, other service ports or public listeners are added or changed.

Read only root-owned, single-link regular reservation files (bounded to
32 bytes) in a root-owned directory with no symlinked path components or
group/world write permission. Files must also lack group/world write permission.
The shared verified reader checks the opened inode and refuses symlinks and
non-regular files. Ports must be decimal integers from 1024 through 65535;
UIDs must be decimal Linux UIDs from 0 through 4294967294. Refusals skip that
entry or invalid-UID account and report one stderr line, never rule stdout.
An absent entry means the account has no such listener and is skipped without
a line, because the five-minute rebuild would otherwise log for every account
that does not run qBittorrent. An unsafe registry directory logs one line and
renders no guard rules.
No reservations are created, assigned or repaired by this read path.

## Consequences

Both setupNetwork and the five-minute trafficLog path consume the same helper.
The normal restore replaces the relevant chains; OUTPUT-only restoration
contains `-F OUTPUT` inside its transaction, and both sequential fallbacks
flush before rebuilding. Repeated applications do not append duplicate rules.
Existing ACCEPT/MARK accounting commands retain their bytes and order.
`pmssTrafficParseOutputRule()` counts only ACCEPT rows, excluding REJECT counters.
Disabling the marker takes effect at the next rebuild. Existing fallback
application failure semantics remain unchanged.

Verification prepared: `MonitoringRulesLoopbackExemptionTest` covers default
bytes, owner/root matches, rule order, validation and unchanged accounting;
`MonitoringRulesLoopbackRegistryTest` covers native/Docker reservations, missing
and malformed entries, home-file independence, ownership, permissions and types.
Registry ownership fixtures require root on the testing machine. Before enabling
a host, run these tests there and verify native and Docker panel access, owner
and root TCP connections, other-UID rejection, repeated rebuilds and traffic
counter continuity. No runtime tests, firewall applications or host changes were
performed on the dev box.

## References

- ADRs 0068, 0102, 0112 and 0213.
- [Netfilter owner matching](https://www.netfilter.org/documentation/HOWTO/packet-filtering-HOWTO-7.html).
- [Docker rootless mode](https://docs.docker.com/engine/security/rootless/).
