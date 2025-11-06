# Testing Orchestration

PMSS splits tests into two tiers:
- Development: `php scripts/lib/tests/development/Runner.php` — hermetic, fast, no network/system mutation.
- Production: `php scripts/lib/tests/production/Runner.php` — read-only probes on live hosts after provisioning.

## Local Testing Scripts
Utility scripts under `scripts/testing/` orchestrate common checks:
- `test-php.sh` — PHP syntax lint and development suite.
- `test-bash.sh` — bash syntax check and optional lint/format when tools are present.
- `test-all.sh` — runs both of the above.
 - `doctrine-lint.sh` — ADR doctrine guardrails (H1, Category, no indexes). Always on in `test-all.sh`.
 - `camelcase-lint.sh` — filename lint for selected first-party PHP directories (lower camelCase filenames). Opt-in via `PMSS_LINT_CAMEL=1`.
 - `docblock-lint.sh` — requires docblocks for classes and public methods in first-party PHP. Opt-in via `PMSS_LINT_DOCBLOCK=1`.
 - `phpstan.sh` — static analysis wrapper (uses `phpstan.neon.dist`). Opt-in via `PMSS_LINT_PHPSTAN=1`.

These utilities augment existing runners. They do not replace the canonical test entry points.

### Lint Opt-ins (safe defaults)
- To run all lints locally without impacting dev hosts:
  - `PMSS_LINT_CAMEL=1 scripts/testing/test-all.sh`
  - `PMSS_LINT_DOCBLOCK=1 scripts/testing/test-all.sh`
  - `PMSS_LINT_PHPSTAN=1 ALLOW_TOOL_SKIP=1 scripts/testing/test-all.sh`
  - `ALLOW_TOOL_SKIP=1` allows skipping tools not installed (e.g., phpstan) rather than failing.

## CI Integration
GitHub Actions run PHP lint, development tests, and bash checks on pushes and PRs. See `.github/workflows/ci.yml`.

## Expectations
- Keep development tests hermetic. Seed variations via temp files and env vars (e.g., `PMSS_OS_RELEASE_PATH`).
- Document any production probe or destructive change in an ADR and PR notes.
- Track planned coverage gaps in `tests/TODO.md` and close them over time.
