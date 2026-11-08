# ADR 0240: Rootless Docker maintenance boundaries

Date: 2026-11-08
Category: architecture

## Status

Accepted

## Context

The rootless Docker config helper mixed storage inspection, pure daemon policy,
format checks and account-owned publication. Updater maintenance mixed command
logging, linger cleanup, unit repair, dependency probes and orchestration.
Configuration changes also maintained a separate mutable change flag, repeated
the write-eligibility decision and selected fuse-overlayfs before evaluating it
in a second phase. These mechanisms obscured the existing convergence order.

## Options Considered

- Retain both mixed modules: the policy and effect boundaries remain implicit.
- Add a generic maintenance plan/executor: introduces a second command schema
  and unnecessary migration of existing callers.
- Keep both includes and public helpers, separating their existing responsibilities
  and using the resulting config to determine whether publication is needed.

## Decision

Keep `user/rootlessDockerConfig.php` as the config acquisition/publication facade.
Its `rootlessDockerStore.php` sibling owns data-root and existing-store inspection;
`rootlessDockerPolicy.php` owns format checks and the ordered pure policy transform.
The transform mutates the same config and operation flags the controller already
uses, without introducing a second plan/result schema. A strict comparison with
the input replaces the independently maintained change flag. Write eligibility
is decided once before MTU and exec-opt filling: MTU cannot authorize a write
when that gate was false, so both stages share the decision.

Keep `update/users/docker.php` as the linger/orchestration facade, loading
`dockerCommands.php`, `dockerInstall.php` and `dockerDependencies.php`.
The first executable fuse-overlayfs candidate returns its probed state directly,
including failure; a failed first candidate still prevents trying the second.
Command output and successful config notices each use one ordered traversal.

Three redundant decision mechanisms disappear: mutable config-change bookkeeping,
duplicated write eligibility and post-selection fuse evaluation state. The five
extractions leave one implementation per responsibility. Every pre-existing
public helper and include remains available. Result keys, operation flags on
failure, JSON field order, warning bytes and daemon command order stay unchanged.

## Consequences

- All runtime modules are below 150 lines. Pure policy can be reviewed independently
  of file creation, ownership checks and daemon actions.
- There are more include files, loaded through the existing relative-file helper;
  deployment must continue to supply the complete operator tree.
- `RootlessDockerMaintenanceCharacterizationTest` records config payloads/results,
  directory/read/write ordering, account-writer metadata, stream log order,
  start/restart/linger-only dispatch and cached fuse-probe behavior. Existing fault
  fixtures follow moved functions into their new modules without changing their
  rejection expectations. Tests execute on the testing machine only.
- Static verification on the dev box is limited to changed-file PHP syntax checks
  and symbol/reference inspection. No live Docker operation or dry run is executed.

## Security Review

Docker's documented attack surface includes daemon API access and untrusted
container configuration. Its CVE history includes CVE-2024-41110, an authorization
plugin bypass through crafted API requests. Rootless execution reduces daemon
privilege, but control of an account's daemon/config still exposes that account's
containers and accessible files. This is an exposure analysis, not a claim about
installed package vulnerability status.

This refactor retains username checks, UID/primary-GID matching, account-side
replacement, mode 0600, unreadable/unsafe-file refusal, lossless JSON checks and
existing-store preservation. It adds no credential handling, logging fields,
web endpoints, public names or listeners. It creates or reuses no primary or
service credential. Arbitrary existing config fields remain preserved, including
any account-supplied proxy settings; the recorded writer tests pin metadata and
output without logging their contents.

## References

- ADR 0027 (rootless Docker lifecycle), ADR 0142 (account file publication),
  ADR 0204 (bridge MTU policy).
- [Docker daemon attack surface](https://docs.docker.com/engine/security/).
- [Rootless mode](https://docs.docker.com/engine/security/rootless/).
- [CVE-2024-41110 advisory](https://github.com/moby/moby/security/advisories/GHSA-v23v-6jw2-98fq).
