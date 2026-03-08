# ADR 0012: Defer default unbound DNS caching on seedbox hosts

Date: 2026-03-08
Category: architecture

## Status
Accepted (do not enable unbound DNS caching by default at this time)

## Context

Issue #151 requests a decision on whether PMSS should enable local unbound DNS
caching by default or remove unbound from the host baseline.

Current repository behavior:
- PMSS phase 2 does not configure `/etc/resolv.conf` to use `127.0.0.1` as a
  default nameserver.
- `scripts/lib/update/services/systemd.php` contains
  `pmssPurgeFailedUnbound()`, which purges unbound only when systemd reports
  the service state as `failed`.
- PMSS currently operates with external resolvers in production workflows unless
  an operator configures a local resolver on purpose.

Operational constraints:
- PMSS must remain stability-first and backward-compatible across mixed Debian
  10/11/12 fleets.
- DNS behavior is fleet-critical. A default change in resolver path can cause
  broad user-visible outages if misconfigured.
- Reliable default unbound adoption requires a full operator-owned policy:
  resolver source of truth, stale-answer bounds, outage rollback, and
  observability thresholds.

## Options Considered

- Option A — Enable local unbound caching by default now.
  - Pros: local cache benefits and reduced repeated upstream lookups.
  - Cons: changes a core host dependency without a mature PMSS resolver policy,
    rollback flow, or production-wide validation matrix.

- Option B — Remove unbound by default everywhere now.
  - Pros: eliminates ambiguity and avoids half-configured local resolver states.
  - Cons: can conflict with existing operator-managed deployments and does not
    preserve optional local resolver use where intentionally configured.

- Option C — Keep external-resolver default and defer unbound default adoption.
  - Pros: preserves current production behavior; avoids fleet-wide DNS behavior
    churn; aligns with stability doctrine.
  - Cons: PMSS does not provide default local DNS caching benefits.

## Decision

Choose **Option C**.

PMSS keeps the current default resolver model (external nameservers) and does
not enable unbound as a default local caching resolver at this time.

Decision boundaries:
- No PMSS-wide default rewrite to `nameserver 127.0.0.1` is introduced by this
  ADR.
- Existing conservative cleanup behavior remains valid:
  `pmssPurgeFailedUnbound()` may purge clearly failed unbound instances to
  remove noise and drift.
- Operator-managed local unbound deployments remain allowed outside the PMSS
  default path.
- Any future default enablement requires a follow-up ADR with explicit
  configuration contract, stale-answer policy, rollback procedure, and
  post-deploy observability checks.

## Consequences

- Positive:
  - Preserves established DNS behavior for existing hosts.
  - Avoids introducing a high-blast-radius resolver change without complete
    policy ownership.

- Negative:
  - PMSS does not gain default local DNS cache acceleration in this cycle.

- Follow-ups:
  - If reconsidered, stage through a controlled pilot with defined success and
    rollback criteria before any default behavior change.

## References

- GH issue #151
- `scripts/lib/update/services/systemd.php`
- `docs/architecture.md`
- `docs/update.md`
