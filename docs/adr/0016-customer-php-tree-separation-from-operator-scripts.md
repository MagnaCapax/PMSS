# ADR 0016: Customer-Facing PHP Tree Separation from Operator `/scripts/`

Date: 2026-05-17
Category: architecture, security

## Status
Accepted (inviolable rule, codified in AGENTS.md).

## Context

PMSS runs per-user lighttpd instances. Each customer's panel is served from `/home/<USER>/www/` as the customer's UID, not as root. The PMSS source tree has two architecturally-distinct directories:

- `/scripts/` — sysadmin tooling, automation entry points, cron jobs, install / update logic. Intentionally **`750 root:root`** by design. This is the operator-only security boundary. Customer users (uid != 0, not in group `root`) cannot **traverse** `/scripts/`, let alone read individual files inside it.
- `etc/skel/www/` — bundled into each user's `www/` at provisioning and synced ongoing by `pmssUserUpdateFiles()` in `scripts/lib/update/users/filesystem.php`. Customer-readable. Per-user lighttpd serves PHP from here.

Between April and May 2026, multiple codex-driven commits placed customer-facing PHP helpers under `/scripts/lib/` and wired them into `etc/skel/www/welcome.php`, `etc/skel/www/info.php`, and `etc/skel/www/stats.php` via guarded `require_once` calls:

```php
if (file_exists('/scripts/lib/welcomeAnnouncements.php')) {
    require_once '/scripts/lib/welcomeAnnouncements.php';
}
```

When customer PHP runs:

1. `file_exists('/scripts/lib/...')` returns **FALSE** — not because the file is missing, but because the customer user cannot traverse `/scripts/`. The `file_exists` guard cannot distinguish "missing" from "permission denied."
2. `require_once` is silently skipped.
3. Functions defined in the lib never load.
4. The call site at the bottom of the file (`if (!function_exists('pmssX')) return '';`) silently returns empty.
5. **Customer's panel renders the dependent feature as empty.** No log, no error, no visible failure marker.

Confirmed customer-visible regressions (fleet-wide, all PMSS servers):

- Announcements list empty (`<h6>Announcements</h6><ul></ul>`)
- Memory pressure indicator missing
- Home RAID degradation / resync notice missing
- "Traffic limit: Unlimited" rendered for paying customers (real cause: `$trafficLimit` stayed at 0 because the lib never loaded)
- Welcome message body missing personalization
- Media stack panel missing
- Docker-inactive note missing
- Deluge password display missing (suspected)

The pattern is **silent partial failure across the fleet**, indistinguishable to customers from "feature not yet available." Some customers don't notice; few ticket. Operator catches it when actively reviewing panels.

## The architectural rule

> **INVIOLABLE.** Customer-facing PHP — anything that runs under per-user lighttpd, anything in `etc/skel/www/*.php`, anything provisioned into `/home/<USER>/www/` — MUST have ALL `require`, `require_once`, and `include` paths inside the customer tree. **NEVER reach into `/scripts/`** from customer PHP.

Customer-facing helpers live in `etc/skel/www/` (bundled per-user, distributed by `pmssUserUpdateFiles()` in `scripts/lib/update/users/filesystem.php`).

If a helper needs operator-collected data (e.g., SMART/NVMe status that requires root to gather):

- Operator-side: `/scripts/cron/<helper>.php` (or similar) writes a **customer-readable artifact** to `/var/log/pmss/<artifact>.jsonl` (mode 644) or similar.
- Customer-side: `etc/skel/www/<helper>.php` reads the artifact, parses it, and renders the customer-visible output.
- The two sides communicate via a shared data file. Customer PHP never executes operator-side code.

Customers do NOT get root access. The `/scripts/` security boundary is NOT punched through. No `chmod o+rx /scripts/`. No group-membership tricks. The boundary is the operator-only design surface; it stays as-is.

## Detection cue (for reviewer + CI)

The smell is dead-simple to grep for:

```bash
rg -n "(require_once|require|include)\s+['\"]\\s*/scripts/" etc/skel/www/
```

Any hit is a layering violation. A CI gate that fails any PR containing such a hit is the structural prevention.

The same antipattern in narrative form: "this require uses an absolute `/scripts/` path AND lives in a customer-served file." Either condition alone is fine; both together is the bug.

## Options Considered

- **Option A — Move each helper + transitive deps to `etc/skel/www/`.** Works for self-contained helpers; fails when operator-side ALSO requires the helper.
- **Option B — New world-readable tree (`/usr/local/lib/pmss/`).** Introduces a third architectural layer; not justified by current dual-use count.
- **Option C — Customer-side helpers read from operator-written artifacts.** The architecturally clean answer. Customer-side renders; operator-side collects.

We accept **Option C as the default pattern**, with **Option A as a special case** for helpers that have no operator-side data dependency (e.g., pure RSS parser, pure cgroup-file reader where the kernel paths are already world-readable).

## Decision

Customer-facing PHP and operator-facing PHP live in separate trees. They communicate via shared data artifacts (Option C), or via standalone helpers bundled in the customer tree (Option A). They DO NOT cross-include.

Codified in:

- `AGENTS.md` — "INVIOLABLE — Customer-Facing PHP Tree Separation" section.
- This ADR.
- The detection cue is a CI gate (planned).

## Consequences

**Positive:**

- Eliminates a whole class of fleet-wide silent regressions.
- Clarifies the security boundary for codex agents reviewing PRs.
- Forces the operator-write / customer-read separation to be explicit, which is also better operationally (operator-side data collection is auditable; customer-side rendering is auditable; the data file is auditable).

**Negative:**

- Some helpers must be duplicated across the two trees (or split into a thin customer-side reader + thick operator-side writer). Acceptable for KISS.
- New customer-tree helpers must be added to `pmssUserUpdateFiles()` in `scripts/lib/update/users/filesystem.php` to distribute. One extra line per helper.

## Migration record (2026-05-15 through 2026-05-17)

Customer-side helpers that violated the rule and were relocated:

| Old (operator-only) | New (customer-readable) | Commit |
|---|---|---|
| `scripts/lib/welcomeAnnouncements.php` | `etc/skel/www/welcomeAnnouncements.php` | `58578ab6` |
| `scripts/lib/user/trafficLimit.php` | `etc/skel/www/userTrafficLimit.php` (customer-side subset) | `a71f6b98` |
| `scripts/lib/webCgroupMemoryStatus.php` | `etc/skel/www/webCgroupMemoryStatus.php` | `2a912aed` |
| `scripts/lib/storageHealth/*` | `etc/skel/www/storageHealthNotice.php` (customer-side subset) | `503e8ec8` |
| `scripts/lib/welcomeMessage.php` | `etc/skel/www/welcomeMessage.php` | `78a21364` |
| `scripts/lib/user/mediaStackPanel.php` | `etc/skel/www/userMediaStackPanel.php` | `78a21364` |
| `scripts/lib/webDockerInactiveNote.php` | `etc/skel/www/webDockerInactiveNote.php` | `78a21364` |

Remaining (pending migration, more involved due to dependency chains):

- `scripts/lib/user/passwords.php` (Deluge password display in welcome.php)
- `scripts/lib/traffic/storage.php` (traffic storage helpers in welcome.php + stats.php)

## Followup actions

1. Two remaining violations land as separate commits (one per lib, both pending Phase B / Phase C per the vplan in sysadmin memory).
2. CI gate that greps for the detection cue and fails PRs on match.
3. Periodic sweep (cron or manual) verifying no new violations have been introduced. Codex agents working on welcome.php / info.php / stats.php should reference this ADR.

## References

- Companion lesson: `memory/lessons/pmss/20260515-gui-helpers-in-scripts-...` in sysadmin repo (private, agent memory).
- Companion vplan: `memory/deep-memory/20260516-vplan-eliminate-all-customer-php-requires-from-operator-tree-violations-...` in sysadmin repo.
- AGENTS.md "INVIOLABLE — Customer-Facing PHP Tree Separation" section (this repo).
- Operator emphatic correction 2026-05-15 ("SINCE WHEN ARE GUI STUFF IN /SCRIPTS/!!?!?!?!?!?") — origin of this ADR.
- Operator emphatic clarification 2026-05-15 ("user account stuff is supposed to be 100% inside user account, inviolable") — inviolable status.
- Operator directive on security boundary ("no fucking punching through security barriers, customers do not fucking get root access") — explicit rejection of chmod fixes.
