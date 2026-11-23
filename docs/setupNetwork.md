# Network Configuration Script

`setupNetwork.php` applies firewall and traffic shaping rules for all seedbox users. It reads settings from `/etc/seedbox/config/network` and optional `/etc/seedbox/config/localnet`.
If the `localnet` file is missing a default range of `185.148.0.0/22` is
created so local traffic can be tracked separately.

There are no command line arguments; simply run:

```
/scripts/util/setupNetwork.php
```

Actions performed include:
- installing iptables monitoring rules
- enabling forwarding and NAT for VPN tunnels
- filtering bogon networks (see `dropPrivateSources` below)
- generating a FireQOS configuration for user bandwidth limits

This script is usually run after user modifications or during system updates.

## Private-range source drops (`dropPrivateSources`)

By default the uplink drops incoming traffic whose source is in private or shared
address space (`100.64.0.0/10`, `172.16.0.0/12`, `192.168.0.0/16`) along with the other
bogon ranges. That is correct anti-spoofing on a datacenter host with a public uplink.
On a NAT, home-LAN or private-bridge install your own admin traffic, including the SSH
session running the installer, arrives from exactly those ranges.

Set `'dropPrivateSources' => false` in `/etc/seedbox/config/network` to omit only those
three drops at the next `setupNetwork.php` run. The other bogon drops (`0.0.0.0/8`,
`127.0.0.0/8`, `169.254.0.0/16`, the documentation ranges and multicast) stay in force.
Absent or `true` renders the same rules as before; only the boolean `false` changes
anything. A fresh `/etc/seedbox/config/network` is seeded from
`/etc/seedbox/config/template.network`, which carries the key with its comment; an existing
config file is never rewritten, so add the key by hand where needed. `10.0.0.0/8` is not
in the drop list either way.

## Optional qBittorrent loopback owner guard

The operator marker
`/etc/seedbox/config/qbittorrentLoopbackOwnerGuard.enabled` enables the
default-off policy: loopback service ports accept connections only from the
owning account and root. It covers native qBittorrent WebUI reservations and
the separate rootless Docker WebUI reservations. The owner’s lighttpd proxy
continues connecting under the account UID.

Only root registry entries under `/etc/seedbox/runtime/ports/` supply ports:
`qbittorrent-<user>` and, when present, `media-stack-qbittorrent-<user>`.
Home-directory display files do not supply firewall settings. An absent entry
is skipped silently; an unsafe or invalid one is skipped with a stderr
diagnostic; other accounts’ rules still render. No marker preserves the existing rules byte for byte.

Enable or remove the marker as root on an approved testing/pilot host. The next
`setupNetwork.php` application or five-minute `trafficLog.php` rebuild applies
the setting; rendering `makeMonitoringRules.php` alone only prints commands.
Removing the marker restores the previous policy at that next rebuild.
See [ADR 0259](adr/0259-qbittorrent-loopback-service-owner-guard.md) for source
boundaries and the required testing-machine checks before rollout.

**Documentation quality**: The code implements many networking tweaks but lacks inline comments or a high level overview. More documentation on expected config values would be beneficial.
