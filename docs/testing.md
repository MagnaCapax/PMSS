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

Torrent-client operation fixtures use `TestCase::pmssMakeEventCallbacks($events,
$results)` for callbacks that append their operation name and return a fixed
value. Results remain explicit, including `false`, `null` and backup arrays;
callbacks share the event array by reference. Argument-dependent behavior,
lock checks, filesystem effects and assertions stay in the individual tests.

In-process environment fixtures use `TestCase::pmssWithEnv($values, $callback)`
to restore unset, empty and existing values even when the callback throws.
Use `pmssWithTrackedEnv()` when the operation also changes other environment
keys. When restoration must occur between resource cleanup steps, use
`pmssCaptureEnv()` and `pmssRestoreEnvMap()` at the original points instead of
copying `getenv()`/`putenv()` restoration branches.

`RuntimeEnvValueTest` covers `pmssConfigRows()` source keys, short-row filtering,
comment policies and bounded remainders; consumers retain field validation.

For in-process tests, `TestCase::pmssCaptureStdout($callback, $environment)`
returns `[result, output]` and restores temporary environment overrides on success
or exception. Pass `pmssPathPrefixedEnvironment()` for command stubs; keep
assertions outside the capture so only the operation's output is collected.
Use the same helper for discarded output and multi-operation captures, returning
an array from the callback when several results must be asserted together.

Child-process fault-injection tests use `pmssInlinePhpLibraryInNamespace()` to
load a library behind namespace-local shims, preserving its `__DIR__` includes.
Define shims before loading; keep prerequisite imports and assertions in callers.
Atomic-publication fixtures share `pmssInlinePhpAtomicPublicationShims()` and
parameterize only the existing temporary-file failure label.
Complete-write interception uses `pmssInlinePhpFileWriteCompleteShim()` in each
child namespace. `pmssInlinePhpLimitedWriteShims()` shares byte-limit failures,
request recording, and successful fallback writes; callers still own scenarios.

`CertbotRenewalLogTest` injects failed seeks and reads, exceptions and errors into
the tail reader. Failed seeks retain an unknown summary without reading, thrown
objects propagate unchanged after stream closure, and growing logs cannot exceed
the requested tail byte budget. Whole-file and tail-summary fixtures preserve
the existing successful results.

`QuotaFstabOptionsTest` verifies that stale quota-file cleanup rejects raw NUL,
CR and LF bytes before trimming the mount path, leaves stale files intact on
rejection, and escapes the rejected path in its warning. Ordinary surrounding
spaces, tabs and trailing slashes retain the existing cleanup count and logs.

`StorageBenchSecurityTest` covers non-finite cached `diskUtil` samples, including
native NaN/infinity and overflowing numeric strings. These use the idle
preflight's existing missing-sample fallback and are omitted from JSONL evidence.
Finite signed samples retain their normalization and thresholds. Namespace-local
telemetry and log shims verify idle evidence without running disk probes; execute
these tests only on a testing machine.

Deluge compatibility-patch tests share fixture execution and symlink-refusal
checks in `DelugeAppTestCase`. Keep patch-specific source, expected bytes,
return-value assertions, and log expectations in each named test; the shared
harness only owns temporary file setup, patch invocation, and content reads.

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
