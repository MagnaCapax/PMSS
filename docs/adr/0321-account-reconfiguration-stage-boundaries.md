# ADR 0321: Account reconfiguration stage boundaries

Date: 2026-11-22
Category: architecture
Status: Proposed

## Context

`scripts/util/userConfig.php` mixed parsing, account checks, authoritative
persistence, display/limit publication, torrent configuration and service work.
Its runtime include mixed process observations and payload policy with GPU and
Docker actions. This made the persistence boundary difficult to characterize
without invoking the root CLI. A one-entry port loop also carried a separate
dirty flag and projected the same SCGI value into another local variable.

## Options Considered

- Keep the combined CLI and rely on source assertions alone.
- Add separate request and persistence records between every stage.
- Keep one preparation result and separate existing responsibilities behind the
  original CLI and runtime include.

## Decision

`userConfigPrepare.php` owns parsing, account validation, mode selection and
authoritative persistence, including the existing welcome-only exits and strict
worker-floor refresh. It returns only the user, home, mode, store and payload
needed by the CLI. `userConfigLimits.php` owns bonus display and traffic/IOPS
publication. `userConfigServices.php` owns GPU policy and cgroup/Docker actions.
`userConfigRuntime.php` retains process observations, mode/payload helpers and
loads those modules without executing them. All existing helper names remain.

The CLI retains torrent configuration, quota application, PID ownership checks,
restart and shell repair. Its derived-port update uses one positive/changed
comparison and persists directly, without a loop, dirty flag or SCGI alias.
Two billing-default sites become one ordered traversal. Temporary path, PID,
quota-label and welcome-payload aliases disappear. No alternate flow remains.

## Consequences

- Counted execution concepts decrease by two: the dirty-flag mechanism is
  removed and two billing-default sites become one. Module boundaries partition
  existing responsibilities; they introduce no new execution mode or policy.
- Runtime code lines decrease from 370 to 358; physical runtime lines decrease
  from 471 to 468 across the CLI, runtime include and three new modules.
- Arguments, coercions, policy defaults, stdout/stderr, exit statuses, account
  guards, authoritative-first order and fail-soft behavior retain their contracts.
- Characterization covers full/named/welcome results, parse and account refusals,
  persistence failures, strict refresh ordering, limit preparation/write failures,
  service command bytes and derived-port persistence. Source assertions follow
  moved code and retain their original requirements.
- Only changed-file PHP syntax checks and static reference/diff review run on
  the devbox. Tests and operational rehearsals run on the testing machine/CI.

## Security Review

The rootless Docker boundary retains account execution, subordinate-ID setup,
Docker/linger policy probes and command bytes. The runc advisory for
[CVE-2024-45310](https://github.com/opencontainers/runc/security/advisories/GHSA-jfvp-7x6p-h2pv)
documents host-file creation through crafted volume races; user namespaces limit
the writable host scope. This motivates preserving account/path boundaries, not
changing container policy during this refactor. See also Docker's
[rootless model](https://docs.docker.com/engine/security/rootless/).

Primary and service credential handling is unchanged. The existing qBittorrent
password sentinel and account-owned mode-0600 writer remain inline; no credential
is added to the preparation result or new diagnostics. Account UID/home checks,
GPU policy gates, root-store authority, secret permissions and existing endpoint,
bind and port behavior are unchanged. Compromise of operator-owned settings can
affect account resource/service policy as before; no home file gains authority.

## References

- [CLI resource boundaries](0221-account-resource-cli-responsibility-boundaries.md)
- [Durable payload boundaries](0246-user-config-payload-and-persistence-boundaries.md)
- [User-management contracts](../contracts.md#user-management-cli)
