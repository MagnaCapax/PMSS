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
