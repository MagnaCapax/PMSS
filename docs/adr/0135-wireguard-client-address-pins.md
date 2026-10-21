# ADR 0135: Keep WireGuard client addresses from the live configuration

Date: 2026-10-19
Category: domain
Status: Accepted

## Context

Peer addresses were derived afresh in account order. A later registration could
change an established address after a hash collision. The live `wg0.conf` already
records each account, public key, and assigned address.

## Options considered

- Re-derive every address on each run. This can move established clients.
- Read valid pins from `wg0.conf`. Chosen: it is the existing root-owned record,
  needs no additional state, and keeps the first refresh after upgrade unchanged.
- Keep a separate root-owned pin file. This would add a second source of truth
  without preserving anything that `wg0.conf` does not already preserve.
- Derive addresses from the uid. This would re-address every existing client.

## Decision

Reserve addresses for registered account/key pairs from the live configuration
before assigning new keys. Keep pinned keys even above the new limit of eight
device keys per account; ignore additional new keys. Preserve peer output order.
When a key file cannot be read, retain that account's pins. An empty account
roster retains the recorded set until the roster can be read again.

## Consequences

Existing addresses remain stable while the same account keeps the key
registered. Removed accounts release their addresses. If `wg0.conf` is lost,
addresses are re-derived as they were before this change.
