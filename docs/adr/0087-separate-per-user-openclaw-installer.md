# ADR 0087: Separate per-user OpenClaw installer

Date: 2026-10-07
Category: security

## Status

Accepted

## Context

OpenClaw is a chat-driven agent that can execute commands as its account. Its
gateway needs a Node version newer than the existing per-user AI tools pin.
It does not support the URL subpath contract of the media stack. On a shared
host, loopback alone does not isolate accounts: any local account can connect
to another account's loopback listener. The OpenClaw token grants the same
effective authority as shell access to the account.

## Options Considered

- Add it to the media-stack installer and proxy it: incompatible with its URL
  contract and expands an existing installer.
- Use a per-user systemd unit: the user manager is not a reliable PMSS
  dependency (ADR 0027).
- Give it a separate per-user installer, port reservation, and user crontab
  watchdog: keeps installation opt-in while using existing PMSS mechanics.

## Decision

`etc/skel/bin/install-openclaw` is a separate customer-run PHP installer. PHP
fits the multi-step checksum, npm, configuration, credential, and cron work
under the repository language policy. It is self-contained and has no
operator-tree includes (ADR 0016). The updater distributes it with the other
per-account commands. It never alters the media-stack or AI-tools installers.

The existing media-stack port catalog reserves an OpenClaw port for every
account during lighttpd apply, without creating a proxy. The marker is
`~/.media-stack-port-openclaw`; a missing or invalid marker prevents install
and start. The gateway binds loopback on that port. Its 256-bit token is
generated per account, kept in a mode-0600 environment file, and referenced
by OpenClaw configuration rather than copied into the main config or argv.
Only explicit `status --show-token` reveals it. Onboarding skips channels by
default; users must configure their own provider and channel access. A bot
credential must be restricted to one owner because anyone who can message
the bot can exercise the account's shell authority.

The user's own crontab gets an `@reboot` and every-minute check line. The
check gives up after five consecutive failed launches until manual `start`.
`uninstall` removes only marked cron lines and program files; `--purge` also
removes account-local OpenClaw state. No root-owned service or user systemd
unit is involved.

## Security review

OpenClaw's published gateway SSRF advisory
[CVE-2026-26322](https://github.com/openclaw/openclaw/security/advisories/GHSA-g6q9-8fvw-f7rf)
and local token-leak advisory
[GHSA-v3j7-34xh-6g3w](https://github.com/openclaw/openclaw/security/advisories/GHSA-v3j7-34xh-6g3w)
show that trusted gateway callers and local listeners are meaningful attack
surfaces. A leaked gateway or bot token grants account-level access through
the agent; it does not grant root directly. Token bytes stay out of command
lines and routine output. The credential file is private from creation and
checked before each start. The gateway binds only loopback and receives no
public lighttpd endpoint. The installer does not reuse any primary account
password or provision a model-provider key.

## Consequences

All accounts reserve one additional port even without installing OpenClaw.
Users supply their own model credentials and explicitly opt into a chat
channel. A full PMSS update must publish the port marker before installation.
The local dry run is PHP lint and hermetic development tests; a live host still
needs an account-owned install, gateway health check, cron reboot check, and
SSH tunnel check after deployment.

## Placement (2026-10-08)

New per-account installers ship in `~/bin`, which is on PATH. The existing
`install-media-stack.sh` and `install-ai-tools.sh` stay in the home root for
now because the web panel, restart path, and customer-facing documentation
invoke them there. The old `~/install-openclaw.php` is removed on refresh
unless the account's crontab still runs it.

## Amendment: browser access and account profile (2026-10-20)

OpenClaw 2026.10.5 was verified to support the full account base path. Install
and repeat install configure `gateway.controlUi.basePath` as
`/user-<user>/openclaw`, `gateway.publicOrigin` as `https://<server hostname>`
(the panel's `gethostname()` source), and `gateway.trustedProxies` as
`["127.0.0.1"]`. Proxy trust supplies attribution only: gateway authentication
remains token mode. No Host-header origin fallback is enabled.

The customer-opted `~/.lighttpd/custom.d/openclaw.conf` keeps the full prefix
for HTTP and websocket upgrades and retains the account Basic-auth realm.
Ownership follows ADR 0032: the account installer owns this optional fragment.
Install/uninstall restart only the account's lighttpd and php-cgi, then probe
the local panel. Failed verification removes the fragment and restarts the
panel again. Repeat installs preserve onboarding, providers and tokens.
The private `pmss-stopped` marker makes an explicit stop persist across cron.

Configure uses the shared managed app row and background launcher, with
explicit consent for account command execution and AI credit usage. The
existing Apps endpoint routes control and Open requests. Open runs
`dashboard --json --no-open` with the private gateway environment, validates
`browserUrl`, and rewrites the origin and websocket `gatewayUrl` to canonical
HTTPS/WSS URLs. The bootstrap token/profile remain single-use (ten-minute
handoff); the long-lived gateway token never enters a URL or routine output.
The handoff is never logged or written to disk; the redirect sends
`Cache-Control: no-store`. Another panel hostname can require one extra
Basic-auth prompt at the canonical hostname.

The Control UI's X-Frame-Options DENY and CSP frame-ancestors none prevent iframe
embedding. The panel's OpenClaw tab stays in-page at `configure.php#apps`
(ADR 0021); the row's Open button opens the UI separately. Cosmetic
Bearer-authenticated UI requests may receive 401 from Basic auth; the realm
must remain intact.

`addUser.php --app-profile=openclaw` writes `.rtorrentDisable` as the account,
skips rTorrent startup and Pikkumies preinstall, and queues the account-owned
installer inside the user's slice only with at least 2048 MiB RAM, 60000 free
file-quota slots, and no existing `~/.openclaw`. Root never writes OpenClaw state.
Default provisioning is unchanged. Missing quota data skips this automatic
preinstall with a reason; manual installs retain their existing quota policy.

Installs without a valid Virsilipas key write private `pmss-provider-pending`.
The minute check performs no provider work without that marker. With it, a
valid key and an unset primary model adopt `virsilipas/default` using the
`CUSTOM_API_KEY` environment secret reference, write `pmss-virsilipas`, remove
pending, and restart once. A user-selected primary removes pending without
changing the provider. Unreadable configuration waits for repair.

Security review includes upstream's
[scope advisory GHSA-qjpc-qf9m-xwmr](https://github.com/openclaw/openclaw/security/advisories/GHSA-qjpc-qf9m-xwmr)
and [pairing bypass GHSA-vvgp-4c28-m3jm](https://github.com/openclaw/openclaw/security/advisories/GHSA-vvgp-4c28-m3jm).
These concern trusted-proxy authentication; this integration retains token mode
and the account realm. A stolen gateway/bootstrap credential grants the
account's shell authority through the agent. No primary password is collected,
stored, or reused. Service secrets and provider markers are mode 0600; launch
logs use a private umask. The gateway binds loopback and is exposed remotely
only through the existing authenticated panel route.

Delivery adds `www/userOpenClaw.php` to `pmssUserUpdateFiles()`. Existing-home
guiv healing also needs that exact flat filename in the billing-side allowlist
and sync list (ADRs 0099/0100); this repository does not change billing code.
Absent helper delivery returns a short unavailable message. Hermetic tests
cover fragment equality, rollback, handoff validation, late-provider decisions,
shared rows, tab detection and provisioning gates. After deployment, verify
browser chat/websocket handoff, panel recovery, start/stop, late-key adoption,
and the read-only system tests on a QA account.
