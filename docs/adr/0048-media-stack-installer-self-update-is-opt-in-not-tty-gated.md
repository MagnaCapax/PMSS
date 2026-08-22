# ADR 0048: Media-stack installer self-update is opt-in, not TTY-gated

Date: 2026-08-22
Category: architecture

## Status
Accepted

## Context
`etc/skel/install-media-stack.sh` (and the sibling `etc/skel/install-ai-tools.sh`)
could self-update at runtime: fetch the latest copy of themselves from GitHub raw
`main` and `exec` it. The trigger was `[[ -t 0 ]]` — "is stdin a TTY".

That proxy produced path-dependent behaviour (GH #800):

- **SSH interactive** has a TTY → self-update fired → the account ran *bleeding-edge
  `main`*, which could be newer than the rest of the platform installed on that host.
- **Web panel** launches the installer with no controlling TTY
  (`etc/skel/www/userMediaStackPanel.php`, `nohup … &`) → self-update was skipped →
  the account ran its local home copy.

So the same one-command install behaved differently depending on how it was invoked.
A customer hit exactly this: the install aborted over SSH (it had self-updated to a
version carrying the Servarr auth-seed step) but completed from the web panel (older
local copy without that step).

Two facts frame the fix:

1. **The home copy is already kept current.** `update.php` refreshes each account's
   `install-media-stack.sh` on every run — it is in the per-account refresh set
   (`scripts/lib/update/users/filesystem.php`) and is overwritten on content change
   (`scripts/lib/update.php`, sha1 compare). So the local copy tracks the *installed
   PMSS version*; the runtime self-update reached *past* that to whatever was newest on
   `main`, creating version incoherence between the installer and the platform it ran on.
2. **The `-t 0` proxy was already rejected once.** ADR-0007 established that "is stdin a
   TTY" is the wrong signal for installer behaviour (the bootstrap uses `/dev/tty`
   presence, not stdin-TTY). The self-update gate was the same abolished proxy.

The self-update mechanism itself is a deliberately-kept, security-hardened feature: GH
#188 flagged the fetch-and-exec as a supply-chain risk and the response was to *harden*
it (verify the downloaded file's shebang and the `# PMSS: … Installer` marker before
`exec`), not remove it.

## Options Considered
- **Option A – Keep the `-t 0` gate.** Rejected: it is the direct cause of the
  panel-vs-SSH divergence and lets an interactive run get ahead of the installed platform.
- **Option B – Self-update on every path (gate on "running from a real file").** Both
  panel and SSH would fetch latest and match — but both would then run ahead of the
  installed platform, and it adds a silent network fetch-and-exec to the panel path,
  widening the supply-chain surface GH #188 worked to contain.
- **Option C – Make self-update opt-in via `--self-update`, default off; rely on
  `update.php` to keep the local copy current.** Every path runs the installed version by
  default → panel and SSH match, versions stay coherent, and the runtime GitHub fetch
  happens only when a user explicitly asks for it.
- **Option D – Delete self-update entirely.** Rejected: contradicts the deliberate
  GH #188 decision to keep and harden the feature; power users lose the ability to pull
  an installer fix without a full platform update.

## Decision
Choose **Option C**.

- `install-media-stack.sh` and `install-ai-tools.sh` default `--self-update` off and
  drop the `-t 0` condition. Passing `--self-update` performs the (still shebang- and
  marker-verified, per GH #188) fetch-and-`exec` of the latest `main` copy.
- `--skip-update`, `--uninstall`, and `--start-stopped` continue to keep self-update off;
  `--skip-update` is now a no-op kept for compatibility.
- The panel and an interactive SSH run therefore execute the same installed copy.

## Consequences
- **Positive:**
  - Panel and SSH behave identically for the default invocation (GH #800 resolved).
  - The installer never runs ahead of the installed platform version.
  - The default path performs no runtime GitHub fetch, shrinking the supply-chain surface
    GH #188 is concerned with; the hardened fetch path remains available opt-in.
  - Consistent with ADR-0007 (do not gate installer behaviour on stdin-TTY).
- **Negative:**
  - An interactive user who wants the newest installer must pass `--self-update` (or run
    `update.php`) rather than getting it implicitly.
- **Follow-ups:**
  - The Servarr auth-seed readiness timeout (the second half of GH #800) is a separate
    change (larger, liveness-aware wait budget); tracked independently.

## References
- GH MagnaCapax/PMSS #800 (panel-vs-SSH install divergence)
- GH MagnaCapax/PMSS #188 (harden self-update exec in skel installers)
- ADR-0007 (install bootstrap interactivity and contract — stdin-TTY is the wrong signal)
- `etc/skel/install-media-stack.sh`, `etc/skel/install-ai-tools.sh`,
  `scripts/lib/update/users/filesystem.php`, `scripts/lib/update.php`
