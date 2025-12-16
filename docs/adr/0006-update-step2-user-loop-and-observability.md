# ADR 0006: Consolidated per-user loop and update-step2 observability

Date: 2025-12-11
Category: architecture

## Status
Accepted

## Context

The `update-step2.php` orchestration evolved organically over ~15 years. Per-user work (ruTorrent refresh, permissions, linger/systemd wiring, rootless Docker, crontab restore, etc.), it originally was a big monolith and was updated with codex/agentic coding into more of an orchestration layer and supposed simplification through splitting it up. The old version was 1000+ LOC.

- `pmssUpdateAllUsers()` for HTTP/skeleton/ruTorrent/plugins/permissions.
- `pmssEnsureLingerAndDockerAllUsers()` for linger + rootless Docker.
- `pmssRestoreUserCrontabs()` for crontab templates.

After the refactor, LLM made each helper built its own user list and ran separate loops. This made it hard to answer simple questions during incidents:

- “Which user/step is the updater currently working on?”
- “Is it stuck in the main per-user flow or in a secondary sweep?”
- Spaghetti code, Copy Pasta. Same code repeated over and over and over.
- Over abstraction.

At the same time, long-running shell commands were only logged *after* completion, so a hung command produced no start marker. When combined with unbounded stdout/stderr accumulation in `runCommand()`, this made OOM/timeouts hard to diagnose from logs alone.
These were originally logged at runtime, as it happens.

## Options Considered

- **Option A – Keep multiple per-user loops, add more comments only.**
  - Pros: no behavioral change.
  - Cons: continues to hide where time is spent; multiple list traversals are a footgun for future refactors. copy pasta shit over abstraction.

- **Option B – Consolidate per-user work into a single orchestrated loop and improve start logging, but keep command execution unchanged.**
  - Pros: clearer story (“one main loop”), better observability; easier to enrich per-user logging.
  - Cons: still fragile to commands that never exit or emit unbounded output.

- **Option C – Consolidate per-user work into single per user main loop orcherstration, add explicit start markers for every shell step, and enforce a bounded, time-limited `runCommand()` contract.**
  - Pros: single per-user loop, visible start markers, central timeout, and tail-limited buffers so OOM and “hung step” symptoms are easier to identify.

## Decision

We choose **Option C**:

- Treat `pmssUpdateAllUsers()` as the single per-user orchestrator for update-step2.
  - All per-user runtime wiring (currently: HTTP/skeleton/ruTorrent/plugins/permissions + linger/systemd/rootless Docker) must be invoked from this main loop as simple function calls instead of launching additional user-list traversals.
  - Secondary “all users” helpers like `pmssEnsureLingerAndDockerAllUsers()` are retained only as shims or for legacy callers; update-step2 no longer uses them.

- Improve observability of update-step2:
  - `runStep()` emits a `[START] <description>` marker before executing the command, so logs show which step is in-flight even if the command never returns.
  - Per-user helpers emit explicit banners when kicking linger/Docker so hangs can be correlated to a specific user.

- Harden `runCommand()` as the central shell runner:
  - Add a default timeout of **300 seconds** (APT/dpkg commands default to **1200 seconds**), configurable via `PMSS_COMMAND_TIMEOUT`.
  - Tail-limit in-memory stdout/stderr buffers (~1 MiB per stream) while still streaming live output.
  - On timeout, terminate the child process best-effort, return a non-zero rc, and log a large `[TIMEOUT]` banner in both the console and logs, but **do not abort** the overall update (soft-fail doctrine).

## Consequences

  - One clear per-user loop for update-step2; easier to reason about what happens “per account” and in what order.
  - Start markers and timeout banners make it obvious which command or user a hung updater is stuck on.
  - Tail-limited buffers in `runCommand()` reduce the risk of a single noisy command exhausting PHP memory.
  - 300s timeout ensures this never gets completely hung anymore. No single shell tasks should take more than a few seconds, at worst 60 seconds.



## References

- ADR 0004 – Shell command guardrails.
- Incident: `docs/incidents/2025-12-08-home-wipe-updateQuotas-listUsers.md`.
- `scripts/lib/runtime.php`, `scripts/lib/update/runtime/commands.php`, `scripts/util/update-step2.php`, and `scripts/lib/update/userMaintenance.php`.
