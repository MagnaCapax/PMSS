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

## Amendment 2026-10-17: preinstalled on new accounts with enough RAM

The operator, 2026-10-17: "infact pikkumies should be preinstalled ..... in
most cases that is, if enough ram." `addUser.php` now queues the wrapper as the
new user, inside the user's slice (`pmssBuildUserServiceShellCommand`), when
the account has at least 1000 MiB RAM and `~/bin/install-pikkumies.sh` exists
(`pmssAddUserPikkumiesPreinstallCommand`, `pmssUserPikkumiesPreinstallMinRamMiB`).
1000 MiB is the floor the media stack measured (a 1000 MiB plan ran the full
stack idle at about 570 MiB). A Hermes process measured 67 to 91 MB (Pikkumies
ADR-0024). Plans below 1000 MiB keep the opt-in command. A count on four
servers on 2026-10-17 found 100 of 118 accounts at or above the floor.

The preinstall sets `PIKKUMIES_SKIP_HERMES=1`, so account creation only clones
Pikkumies, writes its config and crontab lines, and puts it on PATH. The agent
runtime (about 60,000 files and 2 GB in the account's quota) builds later: at
the account's daily `pikkumies update` time, which Pikkumies spreads by uid, or
when the user first runs `pikkumies`. Accounts created together, as when a
server's users move to a new one, therefore never build at the same time. The
wrapper passes no `--accept-terms`: the first interactive session shows the
terms and starts only once the person accepts them.

For new accounts with enough RAM the command is no longer opt-in. PMSS still
adds no service, port or cron logic of its own. Pikkumies adds two tagged lines to
the account's crontab, preserving every other line: the daily update and a
per-minute tick that starts PHP only, and starts the runtime only when a
scheduled job is due. Existing accounts are not changed by this amendment.

The "75 MB download and 257 MB on disk" in Consequences was the standalone
Python of Pikkumies ADR-0014, which ADR-0033 superseded; the runtime now takes
about 2 GB, as measured above.

## Amendment 2026-10-17: existing accounts, host by host

The operator's "infact pikkumies should be preinstalled ..... in most cases
that is, if enough ram" answered a report that said installing on every
account had not been asked for, so it covers accounts that already exist, not
only new ones. `scripts/lib/update/apps/pikkumiesPreinstall.php` runs in
update-step2 and does nothing unless `/etc/seedbox/config/pikkumiesPreinstall.enabled`
exists on the host. Updates reach every server on the same night, while a
change of this size goes out a few servers at a time with a check in between,
so the marker is how the rollout is staged: it is placed on a batch of hosts,
the result is checked, then the next batch.

On a host with the marker, each managed account gets the same command as a new
account (`pmssPikkumiesPreinstallLaunch`: RAM floor, skeleton installer
present, as the user in the user's slice, `PIKKUMIES_SKIP_HERMES=1`), unless it
already has `~/.mcxPikkumies`. The installs run one after another in a single
background chain, so a host's accounts do not clone at the same moment. An
account gets a root-owned state file in `/var/lib/pmss/pikkumies-preinstall/`
when it already has Pikkumies or when its install exits 0, so it is not tried
again and a user who removed Pikkumies does not get it back. An account below
the floor, without the installer, or whose install failed (a download error,
say) gets no state file and is tried again at a later update.

Recreating an account clears its root-owned preinstall marker after the new
skeleton is in place, allowing the next staged update to install Pikkumies in
the rebuilt home. Termination clears the marker after account removal so a
later account with the same name starts without inherited preinstall state.
Both paths leave the marker alone on a failed rebuild or dry-run termination.

These homes hold their owners' data, so the runtime build that follows at the
daily update time leaves 10% of the disk and file quota free, or skips
(Pikkumies `HermesInstall::UNATTENDED_RESERVE`, its ADR-0033 addendum of the
same date).

## Amendment 2026-10-18: the key distribution's writer

The platform's key distribution reaches a node the same way a panel password
change does: billing asks the provisioning backend, which queues one command
for the account's server. That command is `scripts/util/setVirsilipasKey.php
--jsonl --create|--update-only <user> <key>`. Root only checks the arguments,
the account and its home, then switches to the account user before it reads or
writes anything in the home: `~/.virsilipasKey` is ADR-0046 Customer-editable,
so root never touches that path.

The writer keeps the customer's changes. A file whose `endpoint=` is not Pulsed
Media's is the customer's own provider and is left alone, as are a link or a
non-regular file at the path. `--create` is used only for an account that never
had a key; `--update-only` (a new key after "Get a new key") replaces an
existing file of ours and never creates one, so a customer who deleted the file
keeps it deleted. The file the wrapper above creates (our endpoint, empty
`key=`) counts as ours and is filled. The output line never contains the key.

## Amendment 2026-10-20: new accounts get the same state file

A new account's preinstall (`pmssAddUserPikkumiesPreinstallCommand`) wrote no
state file, so the rule above ("a user who removed Pikkumies does not get it
back") did not cover accounts created with Pikkumies preinstalled: on a host
outside the rollout batches, or before the next update marked them, a removed
Pikkumies looked like an account that never had it. The new-account command now
ends with the same chain as the update hook, `( install ) && touch -- <state
file>`, so the state file is written only when the install exits 0 and a failed
download is still tried again. Both writers get the path from one helper,
`pmssPikkumiesPreinstallStateMarkerPath()` in
`scripts/lib/user/pikkumiesPreinstallState.php`: it validates the name, creates
the root-only (0700) state directory when missing, refuses a linked directory,
and returns no path for an existing state file or a link, so a dangling link is
never the target of the root-owned touch.

Accounts created between the new-account preinstall (2026-10-17) and this
change, which have since removed `~/.mcxPikkumies`, cannot be told apart from
accounts that never had it; they stay unmarked. An account that still has
`~/.mcxPikkumies` is marked by the update hook on a rollout host, as before.

## Amendment 2026-10-20: the preinstall RAM floor is 400 MiB

The 1000 MiB floor above was the media stack's measurement, not Pikkumies'. Pikkumies was measured on
its own: an install on a 400 MiB plan (the account slice capped at about 400 MiB high and 500 MiB max)
finished, the slice held about 450 to 470 MiB right after the build, which counts page cache, and a
question answered afterwards took as long as on a larger plan. The floor
(`pmssUserPikkumiesPreinstallMinRamMiB`) is therefore 400 MiB, for new accounts and for the update
hook. Plans below 400 MiB keep the opt-in command. The 1000 MiB media-stack note above stays as
the record of that measurement.

## Amendment 2026-10-24: no terms question on PMSS

The wrapper still passes no `--accept-terms`, and Pikkumies no longer asks on a
PMSS server: the terms it shows are Pulsed Media's own, which the customer
accepted at signup (operator 2026-10-24: no "accepting terms before action in our
own service"). Pikkumies decides this itself from `/etc/seedbox` (its ADR-0032
amendment of the same date), so existing accounts need no change. The test holds
on every PMSS server, including PMSS hosts other operators run.

## Amendment 2026-10-28: a stopped install is finished on every host

"Tried again at a later update" did not hold in two cases. The update hook did
nothing on a host without the rollout marker, so a new account's failed
preinstall was never retried there. On a marked host, an account that already
had `~/.mcxPikkumies` was marked as installed, so an install that stopped after
its clone was marked too. From 2026-10-25 to 2026-10-28 Pikkumies stopped every
install that had no key yet (its ADR-0110), and a new account's preinstall runs
before the platform key reaches it, so those accounts kept a clone with no update
line, no command on the PATH and no runtime.

The hook now treats a clone without `pikkumies/local/hermes-managed/config.yaml`
(written by every finished install and every session start, Pikkumies ADR-0062)
as an install that may have stopped, on every host, marked or not, and whatever
state file an earlier hook wrote for it. It runs the same command as the user
again, preceded by a check, also as the user, that skips the install when the
user's crontab already has the `# pikkumies-update` line (an install that
finished before its key arrived), so root reads nothing the account controls
(ADR 0121). When that command exits 0 it writes "finished" into the state file,
and a clone whose state file says so is not run again, so a user who turned
auto-update off is not updated every night; the empty state file an earlier hook
or a new account wrote does not count. An account without a clone still needs the host marker, so
the rollout stays host by host, and a user who removed Pikkumies (no clone)
does not get it back.
