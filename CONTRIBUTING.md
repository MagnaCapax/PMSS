# Contributing to PMSS

Thanks for your interest in contributing. Please follow the rails below to keep changes safe and reviewable.

## Read First
- `AGENTS.md` — repository rails and principles
- `docs/architecture.md`, `docs/update.md` — system layout and updater flow
- ADRs under `docs/adr/` — decisions relevant to your change

## Workflow
1. Discuss significant changes via issue/ADR before implementation.
2. Keep commits small and focused; write clear messages (what, why, side effects).
3. Update code, tests, docs, and ADRs together.
4. Validate locally:
   - `php -l` on changed PHP
   - `php scripts/lib/tests/development/Runner.php`
   - `scripts/testing/test-bash.sh` if touching shell scripts
5. Open a PR referencing ADRs or issues.

## Guardrails
- Do not modify `etc/skel/www` or third‑party/vendor code without explicit approval.
- Treat dpkg baselines as immutable snapshots; refreshes follow `docs/dpkg-baseline.md` and do not require an ADR.
- Keep development tests hermetic — no network/system mutations.

## CI
PRs run CI checks (PHP lint/tests, bash lint). Fix failures before merge. See `.github/workflows/ci.yml`.

