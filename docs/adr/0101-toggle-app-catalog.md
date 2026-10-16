# ADR 0101: One catalog for secured toggle apps

Date: 2026-10-15
Category: security

## Status

Accepted (operator-directed, 2026-10-15). Supersedes ADR 0100's per-app wiring and resolves issue #10's request for module-like launchers.

## Context

ADR 0100 secured slskd and Copyparty, but each app added its own cron, endpoint, proxy rules, panel metadata, and cleanup literals. Those copies can drift. The credential and account-isolation rules remain necessary.

## Decision

`etc/skel/www/toggleAppCatalog.php` returns inert rows keyed by app ID. A row defines the marker, process identity, launch tokens, config path, extra port services, display text, and credential mode. Root reads only the installed skeleton copy through a guard that rejects symlinks and unsafe ownership or modes. It never loads a customer's `~/www` copy. This is the same root-owned skeleton read as `scripts/lib/mediaStackRecoveryCommand.php`, not the customer/operator catalog sharing that ADR 0067 rejected: the customer tree still never reads `/scripts` (ADR 0016), and both copies ship in the same `update.php` run, which deploys `/etc/skel` before the per-user steps. Customer PHP reads its own bundled catalog and renders each app's config under the account UID. One root cron and one customer endpoint dispatch by catalog key; the old endpoint paths remain as shims.

The current credential mode is `config`: the account generates a separate random password and writes app config and login files at mode 0600. A `cli` mode is reserved for a later app whose login can only be preset through its own CLI. No CLI credentials are passed on argv by this change. Root never writes under `/home`; command tokens are shell quoted, and the detached launch uses umask 077.

The attack surface is unchanged. Copyparty has published browser-session and file-sharing advisories, including [GHSA-rcp6-88mm-9vgf](https://github.com/9001/copyparty/security/advisories/GHSA-rcp6-88mm-9vgf) and [GHSA-x5pq-m9p8-f4vx](https://github.com/9001/copyparty/security/advisories/GHSA-x5pq-m9p8-f4vx); the pinned 1.20.26 installer is newer than the patched releases those advisories name. A leaked app password can expose that account's shared files through its web endpoint, while the primary PMSS account password remains separate. Both apps keep their loopback web binds behind their own app login, which exists before the first start because other accounts on the server can reach loopback; the proxy route adds no authentication of its own; slskd's peer listener retains its separate allocated port. No service credential is logged or placed on argv.

## Considered alternatives

- Keep the per-app files: this would preserve duplicate wiring and make the next app easy to leave partly configured.
- Load the customer's catalog as root: a customer can edit it, so it cannot be trusted for root-side commands or paths.

## Deferred apps

- Cleanuparr: its setup endpoint lets the first visitor create the account, with no safe preset path.
- Medusa: its Python dependency tree is not a single pinned installer artifact.
- Vaultwarden: there is no official standalone binary for this installer model.

## Consequences

An additional secured app needs one catalog row, one customer config renderer, and one pinned installer. The root catalog guard fails closed: an unsafe or missing catalog stops the toggle-app cron before any app starts. Existing slskd and Copyparty markers, config bytes, ports, proxy paths, credentials, state files, and URL paths retain their behavior. Rollback can restore the prior cron and endpoints while preserving account-owned data.
