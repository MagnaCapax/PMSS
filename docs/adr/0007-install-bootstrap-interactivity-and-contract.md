# ADR 0007: Install bootstrap interactivity and contract
Date: 2026-01-04
Category: architecture

## Status
Proposed

## Context

PMSS installation is documented as a single-line bootstrap (`install.sh`) that
hands off to `/scripts/update.php`. Operators frequently run the documented
form via a pipe:

`wget -qO- https://github.com/MagnaCapax/PMSS/raw/main/install.sh | bash -s -- git/main`

In this form, stdin is not a TTY. Historically, `install.sh` treated non‑TTY
stdin as “non-interactive” and skipped the initial hostname and `/etc/fstab`
quota guidance/editing. That caused fresh installs to proceed directly into the
heavy update phase (package baseline + orchestration) without the expected
initial host configuration review.

We need the bootstrap to:
- Ask the essential host configuration questions when an operator is present
  (SSH/console), even when stdin is piped.
- Never hang when no controlling terminal exists (cron/CI/unattended runs).
- Stay a thin bootstrapper; heavyweight orchestration belongs in `update.php`
  and `update-step2.php`.

## Options Considered

- **Option A – Keep current behavior (skip prompts when stdin is not a TTY).**
  - Pros: Never blocks piped installs.
  - Cons: Violates documented/operator expectations; skips essential initial
    configuration review on fresh installs; increases risk of misconfigured
    `/home` and quota behaviour.

- **Option B – Always prompt, regardless of TTY availability.**
  - Pros: Preserves “ask questions” behavior.
  - Cons: Can hang or break unattended automation; unsafe default.

- **Option C – Use a controlling TTY (`/dev/tty`) when available; otherwise
  behave as non-interactive.**
  - Pros: Works for the documented pipe installer in real SSH sessions; still
    safe for unattended runs; minimal change.
  - Cons: Requires small extra plumbing for editors/prompts.

## Decision

Choose **Option C**.

- `install.sh` detects a controlling TTY via `/dev/tty`.
- When stdin is piped but `/dev/tty` exists, interactive editors/prompts use
  `/dev/tty` so the bootstrap still asks the essential questions.
- When no controlling TTY exists (or `--non-interactive` is passed), the
  bootstrap skips prompts and continues without blocking.

Bootstrap prompts/notes remain intentionally limited to:
- Hostname confirmation (or `--hostname` / `--skip-hostname`).
- `/etc/fstab` quota configuration guidance (or `--quota-mount` / `--skip-quota`).
- `/proc` privacy hardening (`hidepid=2`) applied via `/etc/fstab`.
- Persisting `systemd.unified_cgroup_hierarchy=0` into `/etc/default/grub` and
  running `update-grub` when available, with a clear “reboot required” warning
  when the running kernel cmdline does not yet include the option.

## Consequences

- **Positive:**
  - The documented `wget | bash` install flow remains interactive on SSH/console.
  - Unattended installs remain safe and non-blocking.
  - Operator expectations (“ask the basics before heavy provisioning”) are met.

- **Negative:**
  - Slightly more logic in `install.sh` to manage prompt I/O via `/dev/tty`.
  - Some environments may surface terminal quirks; behavior remains guarded by
    “controlling TTY present” detection.

## References

- `docs/install.md`
- `docs/update.md`
- ADR 0004 (shell command guardrails)
- ADR 0006 (observability expectations for long-running operations)
