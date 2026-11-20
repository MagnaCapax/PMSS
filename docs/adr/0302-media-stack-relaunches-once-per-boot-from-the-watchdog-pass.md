# ADR 0302: The media stack relaunches once per boot, from the watchdog pass

Date: 2026-11-20
Category: architecture

## Status
Accepted. Supersedes the note "automatic boot relaunch is intentionally omitted" in
commit `2a13a7b5` (the panel recovery control of #793). Refs #986, #793, #732.

## Context

After a host restart, nginx, lighttpd and the torrent clients return through the normal
boot path. The media-stack apps run in per-user tmux sessions, a restart ends every tmux
server, and nothing relaunched them. `2a13a7b5` shipped the smallest option of #793, the
panel control `install-media-stack.sh --start-stopped`, and left the boot relaunch out
without recording a reason. #793's own text recorded the condition for a later relaunch:
never reintroduce the crash-restart loop that #732 avoided, so once on the first post-boot
cycle only. In practice the apps stay down after every routine restart until the tenant
opens the panel, and accounts have had every app down for weeks.

The watchdog (`scripts/lib/mediaStackWatchdog.php`) stays observe-only for crash loops.
Four properties of a root-side launch from it would turn a once-per-boot convenience
into host-wide failures, and the design below exists to close them:

- A tmux server started under the cron lock inherits the lock descriptor (PHP stream
  descriptors are not close-on-exec and tmux does not close inherited ones), so every later
  tick would skip with "already running" for as long as that tmux server lives (the shape
  of #949 and #905). ADR 0049 keeps the descriptor-export step for watchdogs that start
  child services; the media-stack cron had dropped it.
- A tmux server started from `cron.service` runs outside the tenant's slice (ADR 0023,
  #579), and a synchronous launch runs tenant-writable files (`~/.bashrc.custom`, the home
  installer copy) inside the root loop, so one slow account would stall every other.
- `install-media-stack.sh` ignores unknown arguments, so a home copy that predates
  `--start-stopped` runs the full installer instead.
- A latch written after the launch, or a launch that proceeds when the write fails, turns
  "once per boot" into "every pass".

## Options Considered

- Keep it manual (the status quo): apps stay down, found only when the tenant looks.
- An `@reboot` cron line: it can fire before `/home` is mounted (`nofail`), and the
  watchdog already waits for the mount.
- Per-user systemd units instead of tmux: a larger change that couples to linger; not a
  removal of anything, and out of scope here.
- Relaunch everything, with an opt-out marker: still breaks the guidance that an app
  stopped on purpose stays stopped, and a regenerated alias block would undo a per-app off
  switch.
- Relaunch the apps that were not `failed` in the last pre-restart snapshot, once per boot
  (chosen). No tenant action is needed, a crashed app stays visible, and an app the restart
  killed comes back.

## Decision

1. The watchdog pass (`pmssMediaStackWatchdogRunUser()`) relaunches once per boot, on by
   default. #793 had framed it as opt-in; an opt-in cannot reach the tenant who never opens
   the panel.
2. **Record.** The published snapshot carries `relaunch`: the boot ID
   (`/proc/sys/kernel/random/boot_id`), the selected apps and a `done` flag. No new file.
   - A snapshot written after this boot began (now minus `/proc/uptime`) is never
     relaunched from, whatever its record says: it comes from a deploy onto a running host,
     from a transfer (the target home is still being copied in, and the copy carries the
     source host's boot ID) or from an edit by the tenant. A done record for the current boot
     is written and nothing is launched.
   - A snapshot older than this boot, without a record or with a record of another boot, is
     pre-restart state. A snapshot with no usable timestamp is never relaunched from.
   - Selection: the apps that were `running` or `stopped` in that pre-restart snapshot.
     `failed` apps stay down, and so do `off` apps (turned off in the panel, which writes
     `~/.<app>Disable`). App names come from the fixed app definitions and are read from the
     snapshot by key; names in the tenant-writable file are never used.
3. **Stagger.** The launch waits until uptime exceeds a deterministic per-account delay,
   at most 300 s (`rtorrentProcessStaggerDelay()`, as for rTorrent). The cron never sleeps;
   deferred passes carry the pending selection forward in the record, because absent apps
   read `failed` after three passes and would otherwise drop out.
4. **Latch, then launch.** The record is marked done and published first; the launch runs
   only if that write succeeded. A failed write launches nothing, and a later pass in the
   same boot finds the done record and launches nothing.
5. **Launch.** `pmssLockChildClosePrefix()` followed by
   `pmssBuildUserServiceShellCommand()` in its non-login form: `su` as the tenant inside
   `user-<uid>.slice`. The tenant command is
   `cd ~; { grep -q -- '--start-stopped)' install-media-stack.sh && PMSS_MEDIA_STACK_START_APPS=<ids> nohup /bin/bash install-media-stack.sh --start-stopped; } >/dev/null 2>&1 </dev/null &`
   (the redirect covers the whole backgrounded group, or its subshell would hold the caller's pipe open).
   It returns at once, root reads no tenant-writable file to decide, a home copy without
   the mode is never run, and output is passed through, never captured. The cron passes
   `pmssUserWatchdogLockAcquire` so the lock descriptors are exported and closed in the
   child, as the other watchdogs do.
6. **Installer.** `media_stack_start_stopped()` skips apps not named in
   `PMSS_MEDIA_STACK_START_APPS` when the variable is set (a set but empty list names none).
   An older copy that has the mode but not the variable starts every absent app, which is
   not destructive. A new command-line flag was rejected: an old copy would fall through to
   the full installer.
7. **Skips.** Accounts whose services are parked (`www-disabled`, no `www`, or in the
   moved state, `pmssUserServicesParked()`) are skipped and logged once, and latched.
   Suspension kills every tenant process and moves `www` aside, and a moved account's old
   copy must not run beside its new one. Accounts the watchdog does not observe get no
   relaunch (see Consequences).
8. **Log.** One transition line per relaunch through the existing watchdog transition
   logger.

## Consequences

- Every observed, non-parked account gets its previously healthy apps back after a restart
  with no manual action. An app that crashes after the relaunch stays down and shows as
  failed; the watchdog stays observe-only after that single launch.
- An app stopped with `tmux kill-session` fewer than three passes (about six minutes)
  before a restart is still `stopped`, not `failed`, and is relaunched. Stops through the
  panel write the `off` marker and stay off.
- The snapshot sits in the account's home and travels with a migrated account. The target's
  watchdog observes the account while its home is still being copied in, so a copied
  snapshot never relaunches (it is newer than the target's boot). The post-transfer start-up
  of a moved account is a separate decision (#986 thread) and is not made here. A target
  host restarted in the middle of a transfer can still relaunch from a copy older than its
  boot.
- A tenant edit of its own snapshot is newer than the boot and relaunches nothing.
- A host whose clock reads behind at its first post-boot pass sees the pre-restart snapshot
  as newer than the boot and relaunches nothing: the safe direction.
- Accounts whose install skipped Jellyfin are not observed by the watchdog today (#991), so
  they are not relaunched until that gate is widened. This ADR does not change the gate.
- The aliases that `--start-stopped` evaluates are refreshed on full installer runs (#985),
  so the unattended relaunch does not replay stale alias blocks.
- Reversal is a clean revert; the panel's reader ignores the extra snapshot field.
