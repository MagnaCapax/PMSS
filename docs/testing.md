# Testing Orchestration

PMSS splits tests into two tiers:
- Development: `php scripts/lib/tests/development/Runner.php` — hermetic, fast, no network/system mutation.
- Production: `php scripts/lib/tests/production/Runner.php` — read-only probes on live hosts after provisioning.

## Local Testing Scripts
Utility scripts under `scripts/testing/` orchestrate common checks:
- `test-php.sh` — PHP syntax lint and development suite.
- `test-bash.sh` — bash syntax check and optional lint/format when tools are present.
- `test-all.sh` — runs both of the above.

These utilities augment existing runners. They do not replace the canonical test entry points.

## CI Integration
GitHub Actions run PHP lint, development tests, and bash checks on pushes and PRs. See `.github/workflows/ci.yml`.

## Expectations
- Keep development tests hermetic. Seed variations via temp files and env vars (e.g., `PMSS_OS_RELEASE_PATH`).
- Document any production probe or destructive change in an ADR and PR notes.
- Track planned coverage gaps in `tests/TODO.md` and close them over time.

