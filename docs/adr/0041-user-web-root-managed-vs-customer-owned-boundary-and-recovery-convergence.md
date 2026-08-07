# ADR 0041: User web-root managed-vs-customer-owned boundary and recovery convergence

Date: 2026-08-07
Category: architecture

## Status
Accepted

## Context
Users repeatedly delete `~/www` (or parts of it) and lose the web interface, then
experience the account as broken — the support-ticket theme tracked in #105. The
current watchdog `scripts/cron/checkGui.php` recreates `www/` and `data/` and
restores only `www/index.php` and `www/scriptsInc.php`. After `rm -rf ~/www` the
rest of the panel pages and the entire `/etc/skel/www/rutorrent` application tree
remain absent, so the interface stays broken. The earlier claim that watchdog
recovery was complete was therefore false.

Prior research (#197, closed should-not-implement) rejected broad prevention via
`chattr +i` / generic xattrs / ACLs / a root-owned immutable GUI tree on
user-editable files: those conflict with legitimate customer customization and
multiply update-write coordination across every config-regeneration path. The
decided mechanism is recovery + convergence, not prevention-by-immutability.

Three code paths independently touch the web root today and can drift apart:
provisioning (`scripts/addUser.php`), update
(`scripts/lib/update/userMaintenance.php`, `users.php`,
`users/{rutorrent,http,permissions,filesystem}.php`), and the watchdog
(`scripts/cron/checkGui.php`). `scripts/lib/update/users/rutorrent.php`
`pmssUserUpgradeRutorrent()` already copies the ruTorrent tree from the skeleton
and preserves `share/` — reconstruction logic that the watchdog does not reuse.

## Options Considered
- Option A – Prevention by immutability (`chattr +i` / xattrs / ACLs on user
  files). Rejected in #197: breaks config regeneration during updates, silent
  write failures, coordination burden that scales with codebase velocity.
- Option B – Point-fix `checkGui.php` to restore more files, path by path.
  Perpetuates scattered-decision-logic: three paths each reconstruct differently
  and drift; no single source of truth; customer state inside `www` still at risk.
- Option C – One managed/customer boundary + one shared reconciler that
  provisioning, update, and the watchdog all call, with mutable customer state
  migrated out of `www` so `www` is safely disposable. Chosen.

## Decision
Adopt a single ownership boundary and a shared, path-safe, per-user-locked
web-root reconciler used by provisioning, update, and the watchdog.

**Reconstructible managed interface** (safe to rebuild from `/etc/skel/www`):
the PMSS panel code (`index.php`, `scriptsInc.php`, panel pages), the ruTorrent
application tree (`www/rutorrent/{php,js,lang,images,plugins,css,conf}` minus
per-user overrides), and generated per-user ruTorrent configuration.

**Customer-owned state** (MUST survive deletion/rebuild of the interface):
`~/data`, `~/watch`, `~/session`, `www/public`, `www/rutorrent/share`, supported
ruTorrent user overrides, `~/.rtorrent.rc.custom`, `~/.lighttpd/custom`,
`~/.lighttpd/custom.d`.

**Convergence contract:** provisioning, update, and watchdog MUST converge to the
same web-root state; repeated runs MUST be no-ops. Partial repair MUST add missing
managed files without deleting or overwriting surviving customer files or unknown
additions. Unsafe symlinks / conflicting path types MUST be refused without
following or modifying their targets.

**Migration to make `www` disposable:** mutable customer paths currently inside
`www` are relocated to durable user-owned locations and symlinked back, following
the existing `www/data -> ../data` and `www/watch -> ../watch` model. Recommended
target paths (final decision recorded here):
- `www/public` -> `~/.local/share/pmss/public`
- `www/rutorrent/share` -> `~/.local/share/pmss/rutorrent/share`
- `www/rutorrent/conf/users` -> `~/.config/pmss/rutorrent/users`, only if the
  implementation audit confirms it holds mutable user overrides.
Migration MUST preserve bytes, ownership, and modes, and MUST never overwrite or
delete a conflicting destination — preserve the conflict and log it.

## Consequences
- Positive: `rm -rf ~/www` recovers a usable panel + ruTorrent in one watchdog
  run; customer state survives; one shared reconciler removes the scattered
  three-path drift; no immutability coordination burden on update paths.
- Negative: a one-time per-user migration of mutable paths out of `www`
  (customer-data-touching — must be reversible, idempotent, conflict-preserving);
  update/provisioning/watchdog gain a shared dependency to keep converged.
- Follow-ups: implemented across issues #762 (migration), #763 (shared
  reconciler), #764 (watchdog wiring), #765 (provisioning/update convergence),
  #766 (acceptance tests + logging).

## References
- Issues: #13 (parent, closed and split), #105 (historical partial watchdog),
  #197 (rejected immutability research), #762–#766 (implementation).
- Code: `scripts/cron/checkGui.php`,
  `scripts/lib/update/users/rutorrent.php` (`pmssUserUpgradeRutorrent`),
  `scripts/addUser.php`, `scripts/lib/update/userMaintenance.php`.
- Related: `docs/adr/0016-customer-php-tree-separation-from-operator-scripts.md`.
