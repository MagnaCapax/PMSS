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
- filtering bogon networks
- generating a FireQOS configuration for user bandwidth limits

This script is usually run after user modifications or during system updates.

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
