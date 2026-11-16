# ADR 0114: PMSS hosts are IPv4-only; IPv6 stays disabled

Date: 2026-10-18
Category: architecture
Status: Accepted

## Context

PMSS hosts already run without IPv6. The system-prep sysctl baseline sets `net.ipv6.conf.all.disable_ipv6=1` and `net.ipv6.conf.default.disable_ipv6=1` (`scripts/lib/update/systemPrep/sysctlSettings.php`). ProFTPD is rendered with `UseIPv6 off`, the media-stack installer writes Jellyfin's network config with `EnableIPV6` false, and per-user traffic marking and shaping are built on IPv4 `iptables` rules (ADR 0113). There is no `ip6tables` rule set. None of this was recorded as a decision, so IPv4-only code paths keep getting reported as "IPv6 gaps". This ADR records the decision and the reasons.

IPv4 cannot be dropped: much of what a seedbox talks to, trackers, peers and clients alike, is reachable only over IPv4, and each host is reached by its public IPv4 address. IPv6 would therefore never replace anything. It could only be added next to IPv4.

## Options Considered

- **Dual stack on every host.** Peers with IPv6-only connectivity could connect directly, and IPv4 stays as it is. Every network surface would exist twice: firewall and owner-match rules, per-user marks and shaping classes, monitoring, address plans, reverse DNS and mail authentication records, and DNS records that can drift. The upstream network would have to carry a full IPv6 routing table, which needs more forwarding-table memory per route than IPv4 and brings router hardware replacement forward. If accounts got their own IPv6 addresses, each would be identifiable more narrowly than by the shared host address.
- **IPv6 only for selected services.** Fewer surfaces, but it still needs a second address plan, a second firewall policy and per-service exceptions, and it gives the seedbox product no capability it lacks today.
- **IPv4 only (current state).** One address family, one firewall policy, one shaping path, one set of records. Peers that can only use IPv6 are not reached directly. Trackers and the peer pool a seedbox depends on remain reachable over IPv4, so the product does not lose function.

## Decision

PMSS hosts are IPv4-only. The sysctl baseline keeps IPv6 disabled, and PMSS does not configure IPv6 addresses, routes, firewall rules or services. The reasons, in order of weight:

1. **No advantage for the product.** IPv4 is mandatory and already gives every service full reachability. IPv6 adds no capability a seedbox user needs.
2. **It is purely additive cost.** Because IPv4 cannot go away, dual stack doubles the network surface that must be configured, tested, monitored and kept correct, permanently. That is lasting maintenance and sysadmin burden, not a one-time migration.
3. **Routing.** IPv6 routes tend to be worse than IPv4 routes in practice, and a full IPv6 routing table already takes more forwarding-table memory than IPv4 in the routers that must carry it. Dual stack would consume router capacity faster for no product gain.
4. **Privacy.** Accounts on a host share the host's public IPv4 address, which gives every account the same network identity. Per-account IPv6 addresses would make each account individually identifiable, which works against the privacy the service exists to provide.
5. **Operability.** Short IPv4 addresses can be read, typed and compared by people during operations. IPv6 addresses in practice can only be copied and pasted.

This decision can only be changed by a superseding ADR.

## Consequences

- IPv4-only code paths are correct by design. Shaping, marking, firewall and monitoring code that handles only IPv4 is not a defect and must not be reported as an "IPv6 gap".
- New PMSS code must not add IPv6 configuration. A component that enables IPv6 by default (an application listener, an installer flag) is configured to IPv4, as ProFTPD and the media stack already are.
- `template.wireguard.wg0` no longer carries the IPv6-forwarding `PostUp`; IPv4 forwarding remains owned by `scripts/util/setupNetwork.php`.
- The sysctl removes IPv6 addresses but not the protocol. Applications that listen on the IPv6 wildcard keep working and accept IPv4 connections as mapped addresses. An application that needs a routable IPv6 address does not work on PMSS hosts.
- Peers that can only use IPv6 cannot connect to a PMSS host directly.

## Enforcement (2026-11-16)

The sysctl disables IPv6 addresses, not the socket family. An empty address
list therefore proves neither an IPv4 listener nor an IPv6-disabled client.
The version branch introduced in [ADR 0141](0141-rtorrent-016-family-launch-and-rc-guard.md)
bound only non-0.9.8 rTorrent builds. Its statement that 0.9.8 always listens
on IPv4 is not a safe invariant. Every build must receive an explicit IPv4
bind. See [PMSS #1279](https://github.com/MagnaCapax/PMSS/issues/1279).

The template now keeps the 0.9.8 encoding branch separate from an unconditional
`network.bind_address.set = 0.0.0.0`. A caught `network.block.ipv6.set=yes`
also blocks IPv6 on builds implementing that command; catching its absence
keeps 0.9.8 startable. The existing 0.16-family launcher flag remains in
`etc/skel/www/torrentClientsResolve.php` (see [ADR 0146](0146-rtorrent-store-default-and-fast-exit-fallback.md)).

Existing accounts receive bounded repairs during the normal permissions pass
in `scripts/lib/update/users/permissions.php`, through `userFileWrite.php`:

- The existing encoding migration first brings an exact legacy encoding line
  to the former managed branch. `pmssRtorrentConfigIpv4Replace()` then replaces
  that exact branch with the separated encoding, bind and caught block lines.
- `pmssRtorrentConfigIpv6CatchReplace()` wraps only the complete, bare
  `network.block.ipv6.set = yes` line in `.rtorrent.rc.custom`. Comments, edited
  lines, other values, line endings and all surrounding bytes survive.
- Unsafe/unreadable existing files and failed writes warn; dry runs do not write.
  File metadata is preserved. These repairs neither restart clients nor clear
  fallback state. Changes take effect at the next client restart.

These are bounded convergence repairs under [ADR 0036](0036-pmss-owned-config-files-are-generated-from-a-template-never-parsed-and-patched.md),
not a second updater. Template delivery follows the existing install/update
hierarchy documented in [architecture](../architecture.md), [update](../update.md)
and [install](../install.md). New server provisioning uses these same templates.

### Component register

“Implemented” describes shipped configuration, not a sandbox acceptance result.
Pending runtime evidence must not be turned into an accepted exception merely
because a knob is inconvenient to test. No speculative client setting is shipped.
The register below covers the inspected paths; the broader application census
and runtime results remain required before claiming complete fleet coverage.

| Row / component | Setting and delivery | Upstream reasoning | Status / remaining evidence |
| --- | --- | --- | --- |
| rtorrent-098 / system and store 0.9.8 | Unconditional `network.bind_address.set = 0.0.0.0`; exact managed rc repair | [0.9.8 command definitions](https://github.com/rakshasa/rtorrent/blob/v0.9.8/src/command_network.cc) | Implemented; P2 listener and outbound evidence pending. No `network.block.ipv6` method in this family. |
| rtorrent-016 / store 0.16+ | Same bind plus caught `network.block.ipv6.set=yes`; existing family-gated launcher flag | [0.16.25 command definitions](https://github.com/rakshasa/rtorrent/blob/v0.16.25/src/command_network.cc) | Implemented; P2 must read `network.block.ipv6` from the live SCGI socket, not the file. |
| rtorrent-custom / existing custom rc | Catch the exact bare unsupported command, preserving other bytes | [ADR 0141 catch precedent](0141-rtorrent-016-family-launch-and-rc-guard.md#amendment-2026-10-24-01626-log-group-guard) | Implemented; metadata, idempotence and startup verification pending. |
| sshd | `AddressFamily inet`, only `ListenAddress 0.0.0.0`; existing runtime-template backup and `sshd -t` gate before restart | [sshd_config AddressFamily](https://man.openbsd.org/sshd_config#AddressFamily) | Implemented; sandbox `sshd -t` and socket observation pending. Authentication directives are unchanged. |
| openvpn | `proto udp4` in `template.openvpn.server.config` | [bookworm openvpn(8), --proto](https://manpages.debian.org/bookworm/openvpn/openvpn.8.en.html#proto) | Implemented in template; existing deployed config convergence and live socket verification remain open. |
| wireguard | Remove the forwarding `PostUp`; `setupNetwork.php` owns IPv4 forwarding | [wg-quick PostUp](https://man7.org/linux/man-pages/man8/wg-quick.8.html) | Implemented in template; the existing `wireguardWriteConfig()` flow renders it on configuration refresh. WireGuard's UDP socket family still needs runtime evidence. |
| qbittorrent | Candidate `Session\InterfaceAddress=0.0.0.0`, with empty interface selection; current WebUI already uses IPv4 loopback | [getListeningIPs(), 5.0.5](https://github.com/qbittorrent/qBittorrent/blob/release-5.0.5/src/base/bittorrent/sessionimpl.cpp) | Out-of-scope for this patch's settings: P2 proof absent across system/store and libtorrent families. No managed writer change. |
| deluge | Candidates `listen_interface` and `outgoing_interface`; empty listen interface maps to `0.0.0.0` upstream | [preferencesmanager.py, 2.1.1](https://github.com/deluge-torrent/deluge/blob/deluge-2.1.1/deluge/core/preferencesmanager.py) | Out-of-scope for this patch's settings: P2 proof absent. Neither an empty field nor a static config read proves outgoing IPv6 is blocked. |
| transmission-ipv6-bind | Existing RPC uses a UNIX socket; no documented IPv6-off peer switch | [4.0.6 peer binding](https://github.com/transmission/transmission/blob/4.0.6/docs/Editing-Configuration-Files.md#peers), [4.1.3 settings](https://github.com/transmission/transmission/blob/4.1.3/docs/Editing-Configuration-Files.md) | Accepted documented limitation: `bind-address-ipv6=::` is a wildcard, and an invalid/empty value can fall back. No guessed replacement; peer/outbound runtime evidence remains open. |
| rootless-docker / published media ports | RootlessKit `tcp4` is family-specific; `0.0.0.0:PORT:PORT/tcp` alone can still listen on IPv6 | [RootlessKit IPv6 note](https://github.com/rootless-containers/rootlesskit/blob/master/docs/port.md#note-about-ipv6), [Docker port publishing](https://docs.docker.com/engine/network/port-publishing/) | Out-of-scope for this patch's settings: P2 proof absent for Docker-to-RootlessKit translation. Docker `ipv6=false` alone does not prove host publications are IPv4-only. |
| rclone | Existing rcd launcher uses `--rc-addr 127.0.0.1:PORT` in `etc/skel/www/rclone.php`; outgoing bind is a separate flag | [rclone networking/RC flags](https://rclone.org/flags/) | Implemented listener restriction already present; unchanged. P2 outbound proof pending; no new outgoing setting in this scope. |
| proftpd | Existing `UseIPv6 off` in `template.proftpd` | [UseIPv6 directive](https://www.proftpd.org/docs/directives/linked/config_ref_UseIPv6.html) | Implemented already; unchanged. Runtime check remains a deployment responsibility. |
| jellyfin | Existing `EnableIPV6=false` and loopback bind in `etc/skel/install-media-stack.sh` | [Jellyfin networking](https://jellyfin.org/docs/general/post-install/networking/) | Implemented already; unchanged. Published ports and outbound activity need P2 evidence. |
| sabnzbd | Existing `host=127.0.0.1` in the media-stack installer | [SABnzbd host setting](https://sabnzbd.org/wiki/configuration/4.5/general#host) | Implemented listener restriction already present; unchanged. P2 evidence pending. |
| sonarr / radarr / prowlarr / autobrr / cloudplow | Existing media-stack paths; no settings changed | [Shipped installer](https://github.com/MagnaCapax/PMSS/blob/main/etc/skel/install-media-stack.sh) | Out-of-scope for new writers; per-app listener and outbound census/verification pending. |
| nginx / lighttpd | Existing nginx IPv4 listeners and per-user lighttpd configuration | [nginx listen](https://nginx.org/en/docs/http/ngx_http_core_module.html#listen), [lighttpd server.use-ipv6](https://redmine.lighttpd.net/projects/lighttpd/wiki/Server_use-ipv6Details) | Out-of-scope for new settings; template lint covers explicit IPv6 binds, runtime observations must check defaults too. |
| ntpd / ntpsec | Upstream `interface ignore ipv6`; no PMSS-owned time-daemon writer added | [NTPsec interface policy](https://docs.ntpsec.org/latest/miscopt.html#interface) | Out-of-scope: operator-managed time service. A host-level IPv6 listener must be reported, not attributed to torrent clients. |
| server SOPs / client route examples | Existing external provisioning procedures and WireGuard client README routes | [wg-quick address handling](https://man7.org/linux/man-pages/man8/wg-quick.8.html) | Out-of-scope: external SOPs and client-side routing are not changed by this host-template patch. |

### Blockers and sandbox verification

`scripts/testing/ipv4-template-lint.php` is this ADR's own lint, run by
`scripts/testing/test-php.sh` (so by CI and by `static-lints.sh`). It reads the
active lines of every shipped `etc/**/template.*` file and fails on an IPv6
listen or bind literal (F1), a dual-stack or IPv6 address family (F2), IPv6
turned on in the kernel (F3), or an OpenVPN server protocol that is not
IPv4-specific (F4). The OpenVPN client template keeps `proto udp`: it runs on
the customer's device, and the server name has no AAAA record. It also pins the
lines that must stay: the rTorrent IPv4 bind and the `network.block.ipv6` catch
(P1), `AddressFamily inet` for sshd (P2) and `UseIPv6 off` for ProFTPD (P3).
Comments and IPv4 settings remain valid. `--arg=Ipv4TemplateLintTest` checks
bad and good lines, then runs the real CLI on a private copy of the templates:
exit 0 when clean, exit 1 with an F1 diagnostic after `ListenAddress ::` is
appended. This is a shape guard, not a parser for every application; runtime
checks remain necessary for default behavior.

On the testing VM only:

```text
php scripts/testing/ipv4-template-lint.php --root .
php scripts/testing/ipv4-template-lint.php --arg=Ipv4TemplateLintTest
php scripts/testing/ipv4RuntimeTest.php --arg=RtorrentIpv4ConfigTest
php scripts/testing/ipv4RuntimeTest.php --arg=Ipv4RuntimeTraceTest
PMSS_IPV4_TEST_SANDBOX=1 php scripts/testing/ipv4RuntimeTest.php --arg=Ipv4RuntimeTest --matrix=PATH
```

The runtime checker requires one matrix row per enabled `client@version` in
`torrentClients.json`, plus `rclone@system`, `rootless-docker@system` and
`media-stack@system`. Each row contains `account`, integer `pid`, executable
`binary` (the interpreter for Python daemons), and `trace` file path. Use only
dedicated sandbox accounts. It verifies UID/executable identity, the selected
build via `torrentClientsResolve.php`, all account IPv6 listeners using
`ss -H -6 -lntupe`, rTorrent bind/block state via SCGI, and outbound trace evidence.
Missing builds, identities, socket observations or traces fail, with no blanket
exception switch. The Transmission limitation is not silently converted to a pass.

Outbound measurement uses sandbox-collected `strace -f -e trace=socket,connect`
for that client while the fixture driver requests an IPv4 positive control and
an IPv6-only tracker/remote. Any `AF_INET6` socket/connection attempt fails even
if the syscall returns an error. An IPv4 `connect` is required so an empty trace
cannot pass. The checker does not generate workloads or verify the external
driver's IPv6 stimulus: acceptance must retain that evidence separately. It
also does not yet read back qBittorrent/Deluge settings, inspect container
network namespaces, or prove every media app was started. These are open P2
coverage requirements, not evidence of IPv4-only operation.

No runtime results are recorded in this amendment. Run the named checks and
acceptance cases on the sandbox before promoting any unproven setting or
claiming IPv4-only application coverage. The runtime matrix is a targeted
sandbox gate, not part of the hermetic default CI suite; the lint and pure
exact-line controls run through `test-php.sh`.

### Rejected mechanisms and security review

- `ipv6.disable=1` at kernel boot is **rejected for now**. [ADR 0141's context](0141-rtorrent-016-family-launch-and-rc-guard.md#context)
  records `EAFNOSUPPORT` when an application opens an IPv6 socket with the
  family absent. The existing sysctl stays unchanged; disabling the family
  before all supported daemons are verified risks startup failures.
- An unguarded block command is rejected for 0.9.8, where the command is absent.
  Per-account manual custom-line additions are rejected as the delivery path;
  convergence belongs in the normal updater. There is no dual-stack toggle.
- Transmission wildcard/invalid IPv6 bind values and Docker's bridge IPv6 flag
  are rejected as claims of IPv4-only operation: neither establishes the
  required host-listener/outbound behavior. Other candidate settings wait for P2.
- SSH uses the existing backup/validation/restart flow only. [OpenSSH security
  history](https://www.openssh.org/security.html) includes pre-authentication
  denial of service (CVE-2025-26466) and a root code-execution race in affected
  portable versions. [OpenVPN advisories](https://openvpn.net/security-advisories/)
  include memory-safety failures (CVE-2023-46850). These changes restrict socket
  families; they do not remediate those CVEs. Authentication, account/service
  credential separation, secret permissions, redaction and existing IPv4
  ports/endpoints are unchanged. No credential is read by the repairs, emitted
  in logs or added to configuration. Shell access remains behind existing SSH
  authentication; compromise of that root service remains host-wide.

## References

- `scripts/lib/update/systemPrep/sysctlSettings.php` (IPv6 disable in the sysctl baseline)
- `etc/seedbox/config/template.proftpd`, `etc/skel/install-media-stack.sh`
- ADR 0113 (per-user upload throttle enforcement through IPv4 `iptables` marks)
