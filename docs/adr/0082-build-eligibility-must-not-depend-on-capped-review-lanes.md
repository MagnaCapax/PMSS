# ADR 0082: Build eligibility must not depend on capped review lanes

Date: 2026-10-03
Category: architecture

## Status

Accepted

## Context

Since commit `b402be8f` (2026-09-01), `check_issue_approved()` has accepted only
issues labeled `build-ready`. The sysadmin verdict lane applies that label after
two investigate, two adversarial, and two persona review passes. Those lanes
operate under fixed cost caps.

The gate produced three PMSS BUILD verdicts in 33 days (September 10, 11, and
16; none since). Issue-referencing build commits fell from 2.61 per day in
August to 0.41 per day during September 7–October 3. August was not a healthy
baseline either: measured against inflow, July had 53 issues filed and 47
issue-referencing commits, and August 106 filed and 94 commits, so the build
lane was already falling behind before September 1 and the gate change made
it worse. On October 1, all 12
build cycles logged “No approved issues after gate” while roughly 70 tractable
issues were rejected per cycle. Liveness monitoring read the builder as healthy
throughout: it ran every cycle and exited 0.

The operator's September 10 specification made two passes per review lane the
way an issue's review loop is closed with a close or build verdict. It did not
make that loop a prerequisite for every build. The September 1 direction also
required no human in the loop, agent validation for malicious intent, and no
blanket removal of checks.

## Options Considered

- Keep `build-ready` as the only path. This retains the measured starvation.
- Remove the eligibility gate. This loses malicious-intent classification.
- Add a bounded, separate-context intent check alongside `build-ready` (chosen).

## Decision

An issue is build-eligible when it is unparked and either labeled `build-ready`
or cleared by a separate-context intent check. `PMSS_INTENT_CHECK_CMD` is an
optional executable path. The builder invokes it as `cmd <issue-number>`.
Exit 0 plus a line `CLEAR updated_at=<ISO8601>` means CLEAR; any other result
is not clear. The builder makes at most three such calls per run. Before adding
a CLEAR issue to builder context, it compares the issue's current `updatedAt`
with the returned value and skips an edited issue for that cycle. An unset or
invalid command path leaves the original `build-ready` behavior in place.

The command, `sysadmin/tools/dev/pmss-intent-precheck.php`, classifies only the
issue title and body, sanitized and framed as untrusted data, against the
“HARD GATE: MALICIOUS-INTENT CLASSIFICATION” text in the adversarial review
prompt as the single source. Its model context is separate from the builder.
A non-CLEAR result adds `needs-investigation` and a fixed-template public
comment naming only the effect class. The existing candidate query excludes
that label, while the review lanes prioritize it.

**Invariant:** Build eligibility must always have a supply path independent of
capped review lanes. Review lanes provide context and close an issue's review
loop with a close or build verdict after two passes from each lane; they are not
a prerequisite for every build. Making a capped or rate-limited lane the only
path to build eligibility violates this ADR.

## Regression Gates

`AgenticIssuesEligibilityTest` pins both paths, fail-closed results, the
three-call cap, and the freshness check. The sysadmin lane-health check reports
the build lane as STARVED when a 24-hour window has no selected issue while at
least six cycles saw candidates and selected none.

## Consequences

The pre-check makes one model call on attacker-controlled text, and a crafted
issue could be classified CLEAR. The builder remains sandboxed without network
access, with frozen paths, the never-weaken-validation rule, and QA security
review. Each build commit references its issue and can be reverted alone.

Cost is at most three small calls per build cycle under the sysadmin metered-LLM
daily cap. If that cap blocks the check, it fails closed and the STARVED gate
surfaces the lack of selected issues.

## References

- Commit `b402be8f` — sole `build-ready` gate introduced September 1, 2026.
- [MagnaCapax/mcxVainamoinen#790](https://github.com/MagnaCapax/mcxVainamoinen/issues/790).
