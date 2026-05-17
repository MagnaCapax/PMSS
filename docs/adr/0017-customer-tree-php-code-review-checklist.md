# ADR 0017: Customer-Tree PHP Code-Review Checklist for `etc/skel/www/`

Date: 2026-05-17
Category: architecture

## Status
Accepted (binding for codex, claude, contractor agents reviewing PRs that touch `etc/skel/www/*.php`).

## Context

ADR 0016 codified the architectural separation between operator-only `/scripts/` (750 root:root) and customer-readable `etc/skel/www/`. The CI gate `scripts/testing/customer-php-tree-isolation.sh` catches the structural violation at PR time: `require_once '/scripts/...'` from a customer-tree file fails the build.

The 2026-05-17 incident proved the structural CI gate alone is insufficient. A PR can:

- PASS the structural gate (no `require_once '/scripts/'`)
- Still ship a fleet-wide PHP fatal because a function CALLED from the customer file is only DEFINED in the operator tree

Concrete: `welcomeMessage.php` was relocated to `etc/skel/www/` with its operator-tree requires correctly removed, but the body still called `pmssJsonFileReadAssoc()` — defined only in `/scripts/lib/lighttpd/userFileWrite.php`. The gate passed. Web4 self-updater distributed the file. Every customer's panel hit `Call to undefined function pmssJsonFileReadAssoc()` fatal.

The fix needs an AGENT-level review checklist applied to every PR touching `etc/skel/www/*.php` — automated where possible (Layers 6 + 7 in `memory/deep-memory/20260517-vplan-pmss-ci-cd-playwright-adrs-rules-hardening-...`), agent-checklist-driven where automation has gaps.

## Decision

Every PR that touches `etc/skel/www/*.php` (including new files, modifications, removals) MUST pass the following checklist BEFORE merge. The checklist is enforced by reviewing agents (codex, claude, contractor) and verified by CI Layers 6 + 7 where automation exists.

### Checklist

**1. Require-path safety (CI Layer enforced — `scripts/testing/customer-php-tree-isolation.sh`):**
- All `require_once` / `require` / `include` paths in the file are `__DIR__`-relative or PHP built-ins
- No path starts with `/scripts/`
- No path contains `..` traversal

**2. Function-call safety (CI Layer enforced — `scripts/testing/customer-context-fatal-scan.php`):**
- Every function called from the file is one of:
  - Defined in the same file
  - Defined in another file under `etc/skel/www/` that this file requires
  - A PHP built-in (`get_defined_functions()['internal']` or extension-provided)
- No call to a function defined ONLY in `/scripts/lib/`

**3. Render safety (CI Layer enforced — `scripts/testing/customer-panel-render-harness.php`):**
- File renders cleanly under simulated customer context
- stderr contains no PHP Fatal, Warning, or Notice
- stdout output size meets minimum bytes threshold (per-file)
- Expected feature markers present in output

**4. Architectural-fit review (AGENT-checklist):**
- Does this file mix admin/write functions with customer/read functions? If yes, split — admin/write goes to `/scripts/lib/`, customer/read stays in `etc/skel/www/`
- Is this file > 2x the LOC of its actual customer-call-path usage? If yes, apply GATE 5 (refactor.txt) — strip to minimum
- Does this file copy operator-tree code wholesale rather than extracting the customer-side minimum? If yes, refactor before merge

**5. Distribution-list review (AGENT-checklist):**
- If this is a NEW file in `etc/skel/www/`, is it added to the `$files` array in `scripts/lib/update/users/filesystem.php` `pmssUserApplySkeletonFiles()`? Without this, the file ships into `etc/skel/www/` on each server but never reaches `/home/<USER>/www/`.
- If the file is also expected to propagate via web4 self-updater, has the operator-side `pulsedmedia.com/remote/guiv/` been updated? (Operator-action, not PR-blocking, but flag in PR comments.)

**6. Test coverage review (AGENT-checklist):**
- Is there a unit test under `scripts/lib/tests/development/<File>Test.php` or characterization test for the customer-side functions?
- Does the test use the customer-tree path (`dirname(__DIR__, 4).'/etc/skel/www/<file>.php'`) rather than the deleted operator-tree path?

### Agent-applied review prompt fragment

When a PR-reviewing agent encounters a diff that touches `etc/skel/www/*.php`, it MUST include the following block in its review:

```
## Customer-tree PHP review (ADR 0017)

For each modified or added file in etc/skel/www/:

1. Require-path safety: PASS / FAIL — <details>
2. Function-call safety: PASS / FAIL — <details>
3. Render safety: PASS / FAIL — <details>
4. Architectural fit (no admin/write mixed in, no over-extraction): PASS / FAIL — <details>
5. Distribution list updated (if new file): YES / NO / N/A
6. Test coverage matches new path: YES / NO / N/A
```

The agent fails the PR review if ANY of 1-4 are FAIL, even when CI layers themselves report green (CI catches lexical violations; agent catches design violations).

## Consequences

**Positive:**
- Catches the runtime-fatal regression class at PR time
- Codifies the design-level review that ADR 0016 + CI gate doesn't fully cover
- Lets agents have a load-bearing checklist instead of vague "review carefully"

**Negative:**
- 6-point checklist adds review-cost per PR touching `etc/skel/www/`
- Agent fatigue: if agents skip the checklist, ADR has no teeth — mitigation: include the checklist in `prompts/qa.txt` and `prompts/refactor.txt` so codex agents naturally apply it

## Migration

- All existing `etc/skel/www/*.php` files audited via this checklist at next refactor cycle
- The 7 over-extracted helpers from 2026-05-17 (welcomeAnnouncements, userTrafficLimit, webCgroupMemoryStatus, storageHealthNotice, welcomeMessage, userMediaStackPanel, userPasswords, webDockerInactiveNote) flagged for GATE 5 shrink work
- GitHub Issue (separate) tracks the shrink campaign

## References

- ADR 0016 (parent rule): `docs/adr/0016-customer-php-tree-separation-from-operator-scripts.md`
- AGENTS.md INVIOLABLE section
- CI gate: `scripts/testing/customer-php-tree-isolation.sh` (Layer 1 of 6)
- Companion future-CI: customer-context-fatal-scan.php (Layer 6), render-harness.php (Layer 7) — planned
- GATE 5 in `development/prompts/refactor.txt`
- 2026-05-17 fleet-wide welcomeMessage.php fatal incident (sysadmin lessons referenced from this ADR)
