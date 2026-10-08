# ADR 0090: Separate per-user Pikkumies installer

Date: 2026-10-08
Category: feature

## Status

Accepted

## Context

Pikkumies is a small helper agent that runs as the account owner, inside that
account only, and needs no root. It ships its own installer and updater. PMSS
provides a discoverable command in `~/bin` and Pulsed Media's model endpoint
as the default for each account.

## Options Considered

- Fold it into `install-ai-tools.sh` or `install-media-stack.sh`: expands
  existing installers.
- Use per-user systemd: the user manager is not a dependable PMSS dependency
  (ADR 0027).
- Provide a separate thin installer that delegates to Pikkumies: keeps the
  account-owned lifecycle in one place. Chosen.

## Decision

`etc/skel/bin/install-pikkumies.sh` is an opt-in account command distributed
by the user skeleton update. It clones `MagnaCapax/mcxPikkumies` on first run,
calls `pikkumies update` on reruns, and then calls `pikkumies install`. PMSS
adds no root service, port, or cron logic. Pikkumies manages one daily
`pikkumies update` line tagged `# pikkumies-update` in the account's crontab
and preserves other entries.

The wrapper creates `~/.pikkumiesKey` only when absent, with Pulsed Media's
endpoint in `endpoint=` and an empty `key=`. The endpoint answers only with a
per-user key; this file is the channel a platform later uses to provide one.
Pikkumies owns the `~/pikkumies` symlink. PMSS does not edit tracked files in
`~/.mcxPikkumies`; local settings belong in `~/.pikkumiesKey` or
`~/pikkumies/local/`.

## Security review

The account's primary password is never used as a service credential. The
wrapper creates the key file with mode 0600 and prints no key. A leaked key
could allow use of that account's endpoint access; the wrapper exposes no
listener, port, or web route. Pikkumies runs with the account owner's rights.

## Consequences

Until the mcxPikkumies repository is public, the clone fails with a clear
message. Hermes, the agent runtime, needs Python 3.11 to 3.13. Where the host's
python3 is older (Debian 10/11), Pikkumies downloads a pinned,
checksum-verified standalone Python into the account on x86_64, about 75 MB
download and 257 MB on disk in the user's own quota (Pikkumies ADR-0014).
Updates come daily from the MagnaCapax GitHub
organisation, the same trust `update.php` already places in it.
