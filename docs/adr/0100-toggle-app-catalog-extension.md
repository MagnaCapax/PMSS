# ADR 0100: Secured toggle-app catalog extension

Date: 2026-10-15
Category: security

## Status

Accepted (operator-directed, 2026-10-15).

## Context

qBittorrent, Deluge and rclone already use a customer toggle endpoint, a root cron
watchdog and a per-user lighttpd proxy. slskd and Copyparty need this shape but
their loopback listeners are reachable from other tenants on the same host.
slskd's upstream login defaults to `slskd`/`slskd`; Copyparty without arguments
grants anonymous read/write. A loopback bind alone is therefore not security.

Copyparty has had file-server and browser-session advisories, including
[CVE-2026-32109](https://github.com/9001/copyparty/security/advisories/GHSA-rcp6-88mm-9vgf).
Exposure of an app credential lets another tenant or remote panel user browse and
alter that account's shared data; it does not grant the PMSS account password.
slskd also has network-facing Soulseek behavior, so its listen address is pinned
to loopback. The two apps are run under the account UID and get separate random
service passwords. The release archives are pinned by SHA-256, including the
digests shown by GitHub for those exact release assets.

## Decision

Add two optional watchdog spec fields, `securedMarker` (home-relative path) and
`crashLoop` (stable app ID). Existing specs keep their behavior. A gated spec
skips start until the marker exists. Root keeps consecutive failed-start history
in `/var/lib/pmss/toggle-apps/`; five starts whose process is gone at the next
run yield `gave up`. An observed running process clears the count. A customer
restart atomically replaces a marker; root only stats its inode to clear the
gate. The watchdog publishes `running`, `stopped`, `not secured`, or `gave up`
through a PHP process launched as the account.

Root reserves ports in the existing port-manager namespace and builds proxy
text. It passes only the port and nonsecret proxy text to account-UID PHP. That
code creates mode-0600 app configs and login files, then the secured marker and
proxy fragment. The watchdog's start command tests for a nonempty config before
launching the binary. App passwords never appear in argv or root logs. Customer
PHP remains inside the customer tree (ADR 0016 and ADR 0022); installers never
execute downloaded binaries for version detection (ADR 0034).

slskd uses its upstream `web.port`, `web.ip_address`, `web.https.disabled`,
`web.url_base`, and `web.authentication` keys. Copyparty uses its config-file
`[global]`, `[accounts]` and single `[/]` volume sections, with `rp-loc` and
account-only permissions. Both proxies retain the path prefix.

## Rejected alternatives

- Generalize the per-user crontab supervisors from `install-openclaw`: this
  duplicates the established root watchdog and spreads crash-state policy into
  user crontabs.
- Infer container installs from LinuxServer.io API metadata: it provides neither
  app authentication policy nor base-path configuration, so a generated install
  could expose anonymous or default-login listeners.
- Use the PMSS account password for app login: this violates credential
  separation and would expand the impact of an app credential leak.

## Consequences

The first toggle may take one cron cycle to prepare and start. A separate
billing-side guiv allowlist and sync list must include the new flat PHP files
before existing users can receive them through the heal; this repository only
controls the skeleton and `pmssUserUpdateFiles()` manifest. Rollback disables
the markers, stops the user processes and removes the two cron entries; user
configs and credentials remain with their owners.
