# ADR 0107: Converge torrent-client selections after home moves

Date: 2026-10-17
Category: architecture
Status: Proposed

## Context

The version store is host-local at `/home/.pmss/clients/`; a transfer copies one
`/home/<user>/` tree, and recreate archives and restores that account's home.
Selections in `~/bin/<client>/default` therefore travel while installed store
versions do not. A stale selection can leave qBittorrent's selected launcher
unable to start. The marker `torrentClients.enabled` remains the host opt-in.

## Options considered

- Copy the source store with each home: crosses the shared-store and tenant
  boundary and duplicates host artifacts.
- Rebuild links in root context: would inspect and mutate account-owned paths
  with unnecessary privilege.
- Reuse the account-run link converger after permissions repair and select a
  target default only when the migrated store version is unavailable.

## Decision

Transfer calls the shared post-setup step after `userPermissions` and before
restart. Recreate restores the complete `bin` directory and reaches that same
step through its existing post-handback `pmssUpdateUserEnvironment` call (ADR
0089). The fresh skeleton's displaced `bin` remains in the reviewable backup;
the environment pass refreshes managed files in the restored home.

With the marker absent, the step never creates links. If an account-run probe
finds a store default link, it emits one user-log warning and leaves `bin`
untouched. The qBittorrent watchdog uses the legacy launcher while the marker
is absent, even if a migrated default link remains. With the marker present,
it runs the P0 account-owned link
converger. A store default absent from the target projection or filesystem
falls back to the catalog default under the account identity and writes the
existing customer notice file. Relative account custom-build selections and
their directories are preserved. The store is never part of transfer transport.

## Consequences

The flag is controlled on the target and cannot be forged by home content.
No root process writes into a tenant's `bin`. A missing catalog or unavailable
non-system default leaves the selection for operator review; the post-setup
step stays advisory so a transfer or recreate does not fail after data handoff.

Host-marker opt-in clauses superseded by [ADR 0163](0163-torrent-client-store-on-by-default.md).
