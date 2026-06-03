# ADR 0022: guiv-delivered customer-panel files MUST be self-contained within the guiv delivery set

Date: 2026-06-03
Category: architecture

## Status
Accepted (operator-directed, 2026-06-03; restores a long-standing invariant a refactor broke)

## Context

Existing users' panel GUI files (`welcome.php`, `stats.php`, `info.php`, `index.php`,
`deluge.php`, `qbittorrent.php`, `rclone.php`, `lighttpdRestart.php`, the welcome-chain
helpers) reach EXISTING customers **only** via the guiv auto-update ("the heal"):

```
GitHub (etc/skel/www/*) --daily cron--> billing/web4 /home/billing/wwwReal/remote/guiv/
  version.php  : $fileVersions = sha1 of each glob('guiv/*.php')
  guiFrames.php: ships $fileVersions + framesV2.php (base64+serialize)
  user index.php: fetch -> eval -> for each mismatched file, retrieve.php?file=NAME
  retrieve.php : serves gzcompressed content, ONLY for names on a hardcoded allowlist
  heal writes each file RELATIVE TO the user's www/
```

This delivery path can only ship a file when ALL of the following hold:
1. the file is **www-level** (the heal writes relative to `www/`);
2. its basename matches **`glob('guiv/*.php')`** in `version.php` (so **no dotfiles**, no `../`);
3. its name is on **`retrieve.php`'s hardcoded allowlist**.

**The invariant (held for ~15 years, until 2026-05):** a guiv-delivered file must be
**self-contained within what guiv can deliver** — it must not `require`/`include` any file
that is not itself deliverable to the same location by guiv.

**How it broke (2026-06-03 fleet outage):** a refactor extracted a shared helper library to a
**home-level dotfile** `~/.scriptsInc.php` (functions `pmssWelcomeRequireLocalHelper`,
`pmssFormatBytes`, `pmssFrontend*`, `pmssWelcomeHttpContextCreate`,
`pmssWelcomeSerializedArrayDecode`, …) and made **nine** guiv-delivered www files
`require_once __DIR__.'/../.scriptsInc.php'`. But `../.scriptsInc.php` violates all three
delivery conditions (home-level, dotfile, not allowlisted). So the heal shipped the new
`welcome.php` fleet-wide **without** its shared library →
`Fatal error: Call to undefined function pmssWelcomeRequireLocalHelper()` on every healed
user (verified: 20 of 21 users on one host down). The DRY-extraction was correct engineering
in isolation but broke the guiv self-containment invariant — and there was no test to catch it.

(Separate finding: `retrieve.php`'s `strpos('..', $file)` / `strpos('/', $file)` guards are
reversed — they test whether `$file` is a substring of `'..'`/`'/'`, not the reverse — so the
real gate is the allowlist, not those guards. Fix or remove the dead guards.)

## Options Considered

- **A — Inline the shared helpers into each of the 9 files.** Restores self-containment but
  duplicates ~10 functions across 9 files (DRY violation, 9× maintenance). Rejected.
- **B — Teach guiv to deliver the home-level dotfile** (`../.scriptsInc.php`): special-case
  `version.php`/`retrieve.php` for a `../`-named, dotfile entry. Fragile (path traversal,
  ordering: the dep must land before its dependents), fights every delivery condition. Rejected.
- **C — Relocate the shared library to a www-level, guiv-deliverable file** (e.g.
  `etc/skel/www/scriptsInc.php`); the 9 files require it www-locally
  (`__DIR__.'/scriptsInc.php'`); add it to the per-user manifest, to `retrieve.php`'s allowlist,
  and (auto via the daily cron) to `guiv/`. The whole panel set becomes self-contained within
  www, deliverable by the existing pipeline. **Chosen.**

## Decision

1. **Invariant (this ADR):** every guiv-delivered customer-panel file MUST be self-contained
   within the guiv delivery set. It may only `require`/`include` files that are themselves
   www-level, allowlisted in `retrieve.php`, and present in `guiv/`. No `../` requires, no
   home-level files, no `/scripts/` (ADR 0016), no dotfiles in the dependency graph of a
   delivered file.
2. **Fix (Option C):** move the shared helper library to a www-level guiv-deliverable file;
   update the 9 requires; add it to the manifest (`scripts/lib/update/users/filesystem.php`) and
   to `retrieve.php`'s allowlist. The GitHub→daily-cron→guiv→heal pipeline then delivers a
   self-contained set fleet-wide automatically — no per-user, no per-server manual fixes.
3. **CI gate (dependency-closure test):** add a test that parses every `require`/`include` in
   `etc/skel/www/*.php` and FAILS if any target is not in the guiv delivery set (www-level +
   `retrieve.php` allowlist). A guiv file requiring `../.scriptsInc.php` (or any non-deliverable
   path) fails CI. This single test would have prevented the outage.

## Consequences

- **Positive:** restores the self-containment invariant; the heal can never again ship a panel
  file whose dependency it cannot deliver; the fix flows through the existing pipeline; the CI
  gate makes the invariant enforceable, not tribal knowledge.
- **Negative / migration:** coordinated change — repo (new www helper + 9 requires + manifest)
  AND billing-server `retrieve.php` allowlist must land together and in order (deliver the
  helper before/with its dependents), executed via the verify-each-step procedure (backup
  `guiv/` → edit → confirm sha → test ONE user via deleted `.update` + `.guilog` + render → then
  propagate). A partial landing re-breaks the fleet, so it is one atomic, verified operation.
- **Follow-ups:** implement the dependency-closure CI test; fix/remove the reversed
  `retrieve.php` path guards; ensure new provisioning (skeleton) and the `update.php` per-user
  path also carry the relocated helper.

## References

- ADR 0016 (customer PHP tree separation — no `/scripts/` requires), ADR 0017 (customer-tree
  review checklist), ADR 0021 (top-frame navigation contract).
- Architecture: PMSS GUI auto-update mechanism (version.php glob / guiFrames.php / retrieve.php /
  the heal) — sysadmin memory `20260318-pmss-gui-auto-update-mechanism-full-architecture`.
- Outage 2026-06-03: 9 guiv files require `../.scriptsInc.php`; home-level dotfile not
  deliverable; fleet welcome fatal; pre-existing duplicate reports tickets 111660, 111749.
- Antipattern: render-/delivery-without-verification (sysadmin catalog #237).
