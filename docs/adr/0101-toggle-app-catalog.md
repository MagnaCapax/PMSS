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

## Added catalog apps (2026-10-16)

qui uses credential mode `qui`: its account CLI receives a random service password on stdin before the secured marker is written. A missing `qui.db` forces account setup again. Scryer uses `config`: the account writes a private environment file and separate password file before the secured marker, with authentication enabled at every launch. The env file also sets SCRYER_DISABLE_DEFAULT_ADMIN=true: Scryer keeps a built-in `admin` account with no password next to the named one, and the flag disables it once the named admin exists. Its displayed password is an initial password that the user replaces at first login. Both use loopback binds and preserve their URL prefixes. A leaked service password can access the app's data and actions under that account, but is separate from the PMSS account credential. qui's published CORS advisory CVE-2026-30924 affects versions before 1.15.0; the pinned release is 1.32.0. No matching Scryer advisory was found during this addition; its unauthenticated default remains the principal exposure risk.

## Owner settings for slskd (2026-10-21, Refs #1129)

The generated slskd YAML has no Soulseek account or shares. Owner edits to it
fail the exact-byte check and trigger regeneration, including the web login.
The slskd catalog row therefore names an optional `ownerEnv` file,
`~/.config/slskd/slskd.env`. PMSS never creates or rewrites this file. The
account creates it with mode 0600, using shell assignments such as:

```sh
SLSKD_SLSK_USERNAME='your-soulseek-name'
SLSKD_SLSK_PASSWORD='a-separate-soulseek-password'
SLSKD_SHARED_DIR='/absolute/path/to/music;/absolute/path/to/other music'
```

Single-quote values to preserve spaces, dollar signs, double quotes and the
semicolon separating share paths; escape any embedded single quote using shell
quoting. Use a separate Soulseek password, never the PMSS account password.
After saving, restart slskd from Configure to load the environment.

The existing launch shell sources owner settings under the account UID before
any managed environment. It skips missing, symlinked, non-regular, non-0600,
foreign-owned or syntactically malformed files. This is shell code, like the
existing Scryer environment, and runs only with the account's authority. Root
builds a command from the trusted catalog and never reads the file. Settings
remain outside the web root; neither credentials nor contents enter argv or logs.

[slskd 0.26.1 configuration](https://github.com/slskd/slskd/blob/0.26.1/docs/config.md)
documents environment variables below YAML in precedence. The unchanged managed
YAML therefore continues to pin the web bind, port, URL base, authentication and
peer listener, while the environment supplies the missing account and shares.
Exact-byte validation, generated service credentials and secured-marker gates
remain intact. Parsing or merging owner edits into managed YAML was rejected
under ADR 0036; keeping owner settings separate follows ADRs 0046 and 0121.

Security review checked the upstream
[advisory list](https://github.com/slskd/slskd/security/advisories), which showed
no published advisories on 2026-10-21. Known exposure paths remain the web UI/API,
shared files, peer listener and account-readable environment. A leaked Soulseek
secret exposes that external account; a leaked service login exposes slskd data
and actions as the account. Neither grants the primary PMSS credential. Remote
configuration and script execution remain upstream attack surfaces; this change
does not enable either. Rollback removes `ownerEnv` from the catalog, leaving
the owner file intact and returning to the previous launch behavior.
