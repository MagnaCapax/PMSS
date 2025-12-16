# Metrics Baseline (Docs-Only)

Until exporters exist, treat this as guidance for future work:

- Counters: update runs, steps executed, errors by category.
- Timers: step duration (p50/p95) for key phases (repo refresh, dpkg baseline, user maintenance).
- Gauges: active users, services healthy, queued package count (transitional).

Derive basic trends from JSON logs where possible. Do not add new runtime dependencies without a proposal.

## Code Metrics (Advisory)

- LOC + Complexity Snapshot: `development/loc.sh`
  - Prints LOC by category and two advisory complexity sections:
    - Bash heuristic complexity (counts control-flow tokens)
    - PHP heuristic complexity (dependency‑free, per‑file density /100loc)
  - Use this to spot hotspots; add `#TODO(complexity-refactor)` to high‑density files when touching them.

- PHP Aggregate Metrics: `scripts/testing/phploc.sh`
  - Runs `phploc` over first‑party trees for fast, aggregate metrics: average complexity per class/method, counts (classes, methods, functions), and more.
  - Included in CI as an advisory step; review artifacts periodically to track trends and outliers (e.g., max class complexity).

These metrics are advisory. Keep changes Minimal and opportunistic: refactor when you are modifying adjacent code anyway; avoid broad churn.
