# Testing TODOs (PMSS)

Purpose: Track planned and hard/important tests to implement next. Keep this in sync with docs and ADRs.

Hermeticity & Determinism
- Introduce `PMSS_TEST_MODE=1` toggle to disable jitter/sleeps and force temp paths for any long-running routines.
- Ensure all tests use per-run temp directories; avoid cross-test state via env seeding.

Update Flow Smoke
- Add a dev-safe smoke script that runs `scripts/update.php --dry-run` with hermetic env and temp paths; assert JSON events and step ordering.
- Validate that no filesystem mutation occurs in `--dry-run` mode; capture logs under `/tmp/pmss-tests-root/`.

Naming & Lints
- Expand camelCase filename lint coverage directory-by-directory.
- Add opt-in class/file naming lint across first-party libs (one class per file, name matches file).
- Enforce no-aliases policy on env keys via advisory lint.

Sharp/Net Edges
- Make sharp-edges and net-edges lints strict in CI once the tree is clean.
- Extend net-edges lint to detect non-wrapped HTTP calls in PHP (e.g., `file_get_contents('http://...')`) when appropriate.

Static Analysis
- Raise phpstan level in stages; document suppression policies.

Observability
- Add unit coverage for JSON event helpers (required fields, timestamps, rc, durations) when accessible.

Notes
- Keep tests hermetic: no network/system modifications in dev suite. Use env overrides to inject inputs.
- Production probes remain read-only and should be run separately on live hosts post-deploy.
