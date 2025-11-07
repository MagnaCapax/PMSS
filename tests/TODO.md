# Testing TODOs (PMSS)

Purpose: Track planned and hard/important tests to implement next. Keep this in sync with docs and ADRs.

Hermeticity & Determinism
- Introduce `PMSS_TEST_MODE=1` toggle to disable jitter/sleeps and force temp paths for any long-running routines.
- Ensure all tests use per-run temp directories; avoid cross-test state via env seeding.

Update Flow Smoke
- Add a dev-safe smoke script that runs `scripts/update.php --dry-run` with hermetic env and temp paths; assert JSON events and step ordering.
- Validate that no filesystem mutation occurs in `--dry-run` mode; capture logs under `/tmp/pmss-tests-root/`.

Docblocks (Tighten Coverage)
- Make `docblock-lint.sh` required for `scripts/lib/update/**` as the first gating step (keep advisory elsewhere initially).
- Track coverage (classes/public methods) and expand to `scripts/lib/**` once violations are resolved.
- Add CI job to run docblock lint in required mode for selected directories.

Naming & Lints
- Expand camelCase filename lint coverage directory-by-directory.
- Add opt-in class/file naming lint across first-party libs (one class per file, name matches file).
- Enforce no-aliases policy on env keys via advisory lint.
 - Plan rollout: enable `classname-lint.sh` in CI as advisory, then required per-directory once cleaned.
 - #TODO Flip sharp-edges and net-edges lints to strict in CI once the tree is clean (set `PMSS_LINT_SHARP_STRICT=1`, `PMSS_LINT_NET_STRICT=1`).

Sharp/Net Edges
- Make sharp-edges and net-edges lints strict in CI once the tree is clean.
- Extend net-edges lint to detect non-wrapped HTTP calls in PHP (e.g., `file_get_contents('http://...')`) when appropriate.
 - Add a central HTTP helper (wrapping curl) so all outbound calls flow through `runStep()` and consistent logging.

Static Analysis
- Raise phpstan level in stages; document suppression policies.
 - #TODO Add per-directory phpstan configs to raise to level 2 for `scripts/lib/update/**` first (advisory), then expand.

Observability
- Add unit coverage for JSON event helpers (required fields, timestamps, rc, durations) when accessible.

Notes
- Keep tests hermetic: no network/system modifications in dev suite. Use env overrides to inject inputs.
- Production probes remain read-only and should be run separately on live hosts post-deploy.
