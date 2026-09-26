# ADR 0071: User permissions owner repair preserves subordinate ID ownership

Date: 2026-09-26
Category: architecture
Status: Accepted

## Context

The targeted owner repair in `userPermissions.php` selects home entries whose
UID or GID differs from the account's primary IDs. Rootless Docker can create
entries using IDs allocated to that account in `/etc/subuid` and `/etc/subgid`.
Changing those entries to the primary IDs disrupts their intended ownership on
each boot or update. ADR 0046 makes ownership the primary discriminator and
requires repair paths to preserve it. ADR 0027 establishes rootless Docker as a
supported per-user workflow.

## Decision

The targeted owner repair treats entries owned by the account's primary UID and
GID, or by IDs within the account's respective subordinate ranges, as correctly
owned. It continues to repair entries outside those sets. The `find` action runs
`chown -h` per directory through `-execdir` so the final symlink component is
not dereferenced if a path changes during traversal.

## Consequences

Container-created files retain their ownership across boots and updates. Files
owned by root, other users, or other ranges are still selected for repair. Users
without subordinate IDs retain the same owner-selection predicate as before.
