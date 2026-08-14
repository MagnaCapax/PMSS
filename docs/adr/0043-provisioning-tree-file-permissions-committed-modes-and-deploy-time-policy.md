# ADR 0043: Provisioning-tree file permissions — committed git modes and deploy-time policy

Date: 2026-08-14
Category: security

## Status
Proposed

## Context

PMSS ships a provisioning tree — `etc/skel/` (copied into each customer home by
`useradd --skel /etc/skel -m`) and `etc/seedbox/` (system config + templates) —
on a multi-tenant shared host where cross-tenant privacy is the top operating
value. Two support tickets exposed a permissions regression class tracked as
GH #781 (non-executable data files carry the exec bit) and its inverse GH #779
(a genuine launcher ships without the exec bit). Neither ever failed CI.

Root-cause forensics (internal session lesson, verified first-hand from git
history) traced the excess-exec case to a single commit — `73593f01`
("File permission updates", 2023-05-11) — that flipped **3050 of 3170 tracked
files (96% of the repo) from `100644` to `100755`, mode-only, zero content
change** (identical blob hashes on both sides of every diff — the signature of a
`chmod -R` accident during the SVN→git migration, not a per-file curation). Of
`etc/skel/`'s ~3047 files now at `100755`, roughly 3028 are non-executable DATA
(js, css, images, `.svn-base` metadata, PHP libraries, markdown, ini) that should
be `0644`; only ~25 are genuine executables (`*.sh`, `bin/*`, shebang `.php`
launchers) that must stay `0755`.

Three separate questions were tangled together under "permissions are wrong":

1. **Committed git modes.** Git can store only the executable bit — `100644`,
   `100755`, or `120000` (symlink). It physically cannot represent `0600`,
   `0750`, `0711`, setuid, or world-write. Therefore the committed-mode question
   has exactly **two** correct answers: data → `644`, genuine executable → `755`.

2. **Deploy-time permissions.** Everything finer than the exec bit is set at
   deploy time. `scripts/util/setupPermissions.php` (invoked from
   `scripts/update.php` on every update) already runs
   `find /etc/skel -type f -perm /007 -exec chmod o-rwx` — it **strips all world
   bits**, so skel files land `0750` and are then copied into the customer's own
   home, owned by the customer. **No privilege boundary is crossed by the git-mode
   regression**: `0755` is `rwxr-xr-x` (group/other get read+execute, **not**
   write), and the deploy-time world-strip removes even the read bit for other
   tenants. This is a hygiene / least-privilege regression, fleet-wide in scope
   but cosmetic in deployed effect — **not** an active or possible exploit via a
   git mode change.

3. **Secrets committed as data.** `etc/seedbox/config/api.localKey` and
   `api.remoteKey` are 40-char secret-shaped values committed at `100644` in the
   **public** repo. Their generator `scripts/util/setupApiKey.php` would `chmod
   600` per host, but it is **dead** (no real callers; only self-referenced) and
   only generates when the file is absent — a shipped committed placeholder
   defeats regeneration. Their sole consumer `serverApi.php` (the remote C&C
   client) was **deleted 2025-09-21**. The whole `api.*` family is dead code.

The regression escaped CI because `scripts/testing/exec-bit-lint.sh` checks only
first-party PHP — it inspects neither the data files that carry excess exec (#781)
nor the non-PHP launchers missing exec (#779). The gap is a coverage gap, not a
rule gap.

## Options Considered

- **Option A — `git revert 73593f01`.** Rejected. That commit also legitimately
  added `+x` to some real scripts, so a literal revert re-breaks them (re-creating
  the #779 class), and it conflicts with 3 years / 3000+ intervening commits.
- **Option B — Blanket `git update-index --chmod=-x` over the whole tree.**
  Rejected. Strips the exec bit from the ~25 genuine executables — this IS the
  #779 inverse regression, applied deliberately.
- **Option C — Rely on deploy-time `setupPermissions.php` alone; leave git at 755.**
  Rejected as the *sole* mechanism. A fresh host before its first update, or any
  provisioning path that skips `setupPermissions`, falls back to the committed
  `755` state; and CI never catches future drift in either direction. Kept as the
  deploy-time layer, not as the source-of-truth fix.
- **Option D — Immutability (`chattr +i` / xattrs / ACLs) on provisioned files.**
  Rejected; already rejected for the adjacent web-root concern (ADR-0041 / #197).
  Wrong layer — this ADR is about committed modes, not runtime tamper-protection.
- **Option E — Two-track type-aware normalization + content-aware lint + deploy-time
  secrets policy, and DELETE the dead `api.*` family rather than re-perm it.**
  Chosen.

## Decision

Adopt a **two-track permissions policy** that follows the grain of what each layer
can actually express.

### Track 1 — Committed git modes (the only two values git can store)

| File class | Committed mode | Definition (principle, not filename list) |
|---|---|---|
| Non-executable data | `100644` | Anything without an interpreter/loader entry point: js, css, images, `.svn-base`, PHP libraries (included, never invoked directly), markdown, ini, data. |
| Genuine executable | `100755` | A file whose **first bytes are a shebang** (`#!…`), OR a compiled binary under a `bin/` directory, OR an explicit launcher script invoked directly. `*.sh` and `bin/*` are strong signals used as calibration, **not** the rule. |
| Symlink | `120000` | Unchanged. |

The correct one-time normalization is **type-aware** `git update-index --chmod=-x`
on the data files only, keeping `755` on genuine executables — never a blanket
revert and never a blanket `-x`. This runs in the **development repo / dev
pipeline**, not on production hosts.

### Track 2 — Deploy-time permissions and secrets (owned by `setupPermissions.php`)

Everything finer than the exec bit is a deploy-time concern and stays with
`scripts/util/setupPermissions.php`:

- **World-bit strip** on `/etc/skel` and `/etc/seedbox` — keep the existing
  `chmod o-rwx` behavior. Skel data lands `0640`, executables `0750`, copied into
  the owner's home.
- **Genuine live secrets → `0600` at deploy, and never committed.** A real secret
  must be `.gitignore`d and generated per host (absent-file generation), never
  shipped as a committed placeholder that defeats regeneration.
- **The `api.*` family is DELETED, not re-permed.** It is dead code (consumer
  removed 2025-09-21; generator has no real callers). "The best part is no part":
  removing the family eliminates the committed-secret exposure and the dead
  generator in one move. Tracked by its own removal issue — this ADR defers the
  mechanics there and only records that re-permissioning dead secrets is the wrong
  fix.
- **`.bash_history` in `etc/seedbox/skel/srvmgmt/`** is a runtime artifact and
  should not be committed at all (follow-up, minor).

### Home-directory mode (`/home/<user>`)

Recommended default: **`0750`** (owner `rwx`, group `r-x`, **no** world bits).
Rationale: privacy-adequate — no cross-tenant read (Cardinal privacy value), and
**non-breaking** because it matches the current deployed reality. `0700` (stricter,
no group traversal) is a legitimate hardening but changes deployed behavior and
could break any group-traversal consumer, so it is left as an operator-reserved
decision rather than silently adopted. `0711` (traversal-only, the cPanel default)
is **rejected**: PMSS runs a per-user lighttpd **as the user**, so there is no
suexec/CGI traversal requirement that `0711` exists to serve.

### Enforcement mechanism (the single new artifact)

Extend CI with **one content-aware, shebang-detecting, whole-tree skel-mode lint**
that replaces the PHP-only `scripts/testing/exec-bit-lint.sh`. It fails on BOTH
directions in a single pass: a data file carrying the exec bit (#781) and a
shebang/launcher file missing it (#779). **Language: PHP** — it must read file
first-bytes, classify by content, branch on file type, and emit structured
findings; that is program logic, not bash glue. This is the one part that did not
exist and must be built; every other mechanism above already exists and is reused.

## Consequences

- **Positive:** committed modes match convention (data `644`, exec `755`); one CI
  lint closes the entire #781/#779 regression class in both directions; the dead
  `api.*` family and its committed public secrets are removed, not preserved;
  deploy-time secret handling is codified at `0600` + gitignore + per-host
  generation; the multi-tenant home-directory boundary is written down (`0750`).
  The policy is minimal — it follows what git and the deploy layer can each
  actually express, so there is nothing to over-engineer.
- **Negative:** the one-time Track-1 normalization touches ~3028 files (mode-only,
  fully reviewable as a git diff). The residual risk is **misclassifying a genuine
  executable as data** and stripping its exec bit — mitigated by shebang/content
  detection, by the lint being authoritative on "what is executable," and by human
  review of the executable list before the normalization commit. Committed `.svn`
  / `.svn-base` cruft is adjacent pre-git debt surfaced here but out of scope.
- **Follow-ups:** (1) type-aware `git update-index --chmod` normalization for
  #781/#779; (2) build the PHP whole-tree content-aware skel-mode lint and wire it
  into CI; (3) delete the dead `api.*` family (own removal issue) rather than
  re-perm it; (4) stop committing `.bash_history`; (5) purge committed `.svn*`
  metadata (separate hygiene issue). **Operator-reserved:** (a) rotate and/or
  scrub-from-history the previously-committed `api.localKey` / `api.remoteKey`
  values — deleting them from `HEAD` leaves the 40-char values in the public repo's
  history; (b) whether to tighten `/home/<user>` from `0750` to `0700`.

## References
- Issues: GH #781 (excess exec on skel data), GH #779 (missing exec on launcher),
  and the tracked `api.*` dead-code removal issue.
- Commit: `73593f01` "File permission updates" (2023-05-11) — the 96%-of-repo
  mode-only bulk chmod that is the root cause.
- Code: `scripts/util/setupPermissions.php` (deploy-time world-strip; the Track-2
  owner), `scripts/update.php` (invokes it on every update),
  `scripts/testing/exec-bit-lint.sh` (PHP-only lint to be replaced),
  `scripts/util/setupApiKey.php` (dead generator), `serverApi.php` (deleted
  consumer, 2025-09-21).
- Related ADRs: `docs/adr/0016-customer-php-tree-separation-from-operator-scripts.md`,
  `docs/adr/0036-pmss-owned-config-files-are-generated-from-a-template-never-parsed-and-patched.md`,
  `docs/adr/0041-user-web-root-managed-vs-customer-owned-boundary-and-recovery-convergence.md`.
- External convention (a `0755` non-executable file is wrong-by-convention; correct
  is `0644`): Debian Policy, `login.defs`, Red Hat guidance, CIS Benchmarks, CISA
  hardening guidance. Home-directory mode tradeoff (`0700` privacy vs `0711`
  traversal-only vs `0750` group traversal): cPanel default is `0711`, not
  applicable to the PMSS per-user-lighttpd-as-user model.
- Root cause established by internal first-hand git-history forensics (session lesson).
