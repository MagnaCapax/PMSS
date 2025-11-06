# Metrics Baseline (Docs-Only)

Until exporters exist, treat this as guidance for future work:

- Counters: update runs, steps executed, errors by category.
- Timers: step duration (p50/p95) for key phases (repo refresh, dpkg baseline, user maintenance).
- Gauges: active users, services healthy, queued package count (transitional).

Derive basic trends from JSON logs where possible. Do not add new runtime dependencies without a proposal.

