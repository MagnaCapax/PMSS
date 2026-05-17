# Testing Orchestration

PMSS splits tests into two tiers:
- Development: `php scripts/lib/tests/development/Runner.php` — hermetic, fast, no network/system mutation.
- Production: `php scripts/lib/tests/production/Runner.php` — read-only probes on live hosts after provisioning.

## Local Testing Scripts
Utility scripts under `scripts/testing/` orchestrate common checks:
- `test-php.sh` — PHP syntax lint, customer-tree checks, panel render harness, and development suite.
- `test-bash.sh` — bash syntax check and optional lint/format when tools are present.
- `test-all.sh` — runs both of the above.
- `customer-context-fatal-scan.php` — token-aware customer PHP check that reports `OPERATOR_TREE_FUNCTION_LEAK` when `etc/skel/www/*.php` calls a bare function unavailable from the customer tree or PHP built-ins.
- `customer-panel-render-harness.php` — CLI render check for the customer panel pages (`welcome.php`, `info.php`, `stats.php`) under a synthetic customer home.
- `doctrine-lint.sh` — ADR doctrine guardrails (H1, Category, no indexes). Always on in `test-all.sh`.
- `camelcase-lint.sh` — filename lint for selected first-party PHP directories (lower camelCase filenames). Opt-in via `PMSS_LINT_CAMEL=1`.
- `docblock-lint.sh` — requires docblocks for classes and public methods in first-party PHP. Opt-in via `PMSS_LINT_DOCBLOCK=1`.
- `phpstan.sh` — static analysis wrapper (uses `phpstan.neon.dist`). Opt-in via `PMSS_LINT_PHPSTAN=1`.
- `classname-lint.sh` — checks class name matches file basename (tests and first-party libs). Opt-in via `PMSS_LINT_CLASS=1`.
- `sharp-edges-lint.sh` — flags raw `rm -rf`/`mv`/`chmod -R`/`chown -R`/`chgrp -R` outside wrappers. Runs by default in advisory mode, but **always fails** when it detects catastrophic patterns such as `rm -rf /`, `rm -rf /home`, `rm -rf /home/$var`, or `rm -rf $var` without quoting.
- `net-edges-lint.sh` — flags raw `curl`/`wget`/`nc`/`telnet` usage outside `runStep()`; runs by default in advisory mode.
- `check-tools.sh` — prints availability of optional tools (phpstan, shellcheck, shfmt, rg) for predictable local runs.

These utilities augment existing runners. They do not replace the canonical test entry points.

### Lint Opt-ins (safe defaults)
- To run all lints locally without impacting dev hosts:
  - `PMSS_LINT_CAMEL=1 scripts/testing/test-all.sh`
  - `PMSS_LINT_DOCBLOCK=1 scripts/testing/test-all.sh`
  - `PMSS_LINT_PHPSTAN=1 ALLOW_TOOL_SKIP=1 scripts/testing/test-all.sh`
  - `PMSS_LINT_CLASS=1 scripts/testing/test-all.sh`
  - `ALLOW_TOOL_SKIP=1` allows skipping tools not installed (e.g., phpstan) rather than failing.

### Advisory Lints (always on)
- `sharp-edges-lint.sh` runs with `PMSS_LINT_SHARP_STRICT=0` and reports destructive primitives used outside wrappers.
- `net-edges-lint.sh` runs with `PMSS_LINT_NET_STRICT=0` and reports raw network calls outside wrappers.

## CI Integration
GitHub Actions run PHP lint, development tests, and bash checks on pushes and PRs. See `.github/workflows/ci.yml`.

## Expectations
- Keep development tests hermetic. Seed variations via temp files and env vars (e.g., `PMSS_OS_RELEASE_PATH`).
- Document any production probe or destructive change in an ADR and PR notes.
- Track planned coverage gaps in `tests/TODO.md` and close them over time.
