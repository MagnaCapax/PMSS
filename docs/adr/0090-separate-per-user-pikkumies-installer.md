# ADR 0090: Separate per-user Pikkumies installer

Date: 2026-10-08
Category: architecture

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

The wrapper creates `~/.virsilipasKey` only when absent (and not a link), with
Pulsed Media's endpoint in `endpoint=` and an empty `key=`. The endpoint answers
only with a per-user key; the account owner pastes the key shown in the client
area, or the platform's key distribution fills the same file. Pikkumies reads
`~/.pikkumiesKey` first (a provider of the user's own), then `~/.virsilipasKey`.
Pikkumies owns the `~/pikkumies` symlink. PMSS does not edit tracked files in
`~/.mcxPikkumies`; local settings belong in `~/pikkumies/local/`.

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

## Amendment 2026-10-17: launched, key file ~/.virsilipasKey

The hold of 2026-10-08 is lifted: the LLM proxy's per-user keys and accounting
are live (keys for every active client, opening and per-invoice grants), and the
operator directed distribution on 2026-10-17 ("just start it and distribute the
installation"). The operator also named the key file the same day: "virsilipas
key should be naturally named as virsilipas or virsilipasKEy not pikkumiesKey",
so the wrapper now creates `~/.virsilipasKey` instead of `~/.pikkumiesKey`.
Pikkumies (from mcxPikkumies 11a6234) reads it after `~/.pikkumiesKey`, takes
endpoint and key from the same file, ignores a key file that is a link, and
refuses to build its agent runtime when the account's file quota cannot hold it
(about 65,000 files and 2.5 GiB; measured 60,572 files and 2.0 GB on a PMSS
account), so a small account gets a clear message instead of a half-built tree.

## Amendment 2026-10-17: the installer updates itself by default

The operator, 2026-10-17: "also it neesd to be the auto updating type like
install media stack one has" and "self update default enabled, --arg should be
disable". The wrapper now fetches its own newest copy from PMSS main
(`etc/skel/bin/install-pikkumies.sh`) and runs it with `--skip-update`, so the
fetched copy never fetches again. It does so only when line 1 is the bash
shebang and line 2 is `# PMSS: Pikkumies Installer`, the same check the media
stack and AI tools installers use; anything else (no curl or wget, a network
error, an HTML error page) leaves the local copy running. `--no-self-update`
keeps the local copy. The default differs from those two installers (opt-in
since GH #800, so the web panel and SSH behave the same and SSH does not run
ahead of the installed platform): this wrapper has no web panel caller and uses
no other PMSS file, and it already fetches Pikkumies from GitHub on every run,
so the self-update adds no new source. The wrapper comment now says it changes
shell files only when neither `~/bin` nor `~/.local/bin` is on PATH, which is
what the Pikkumies installer does.
