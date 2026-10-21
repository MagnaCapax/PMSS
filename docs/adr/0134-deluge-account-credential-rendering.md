# ADR 0134: Render Deluge credentials per account

Date: 2026-10-19
Category: security
Status: Accepted

## Context

Public Deluge templates carried a shared daemon password and Web UI hash.
Provisioning rewrote web.conf from that template before attempting rotation;
a failed rotation could leave the shared value usable on a loopback service
reachable from other local accounts.

## Options considered

- Keep the template value and rely on later rotation: failure leaves a shared
  working credential.
- Change the service bind alone: existing clients and the Web UI still require
  a credential, and local access remains shared.
- Render from a generated account credential and refuse an unresolved shared
  value at start: selected.

## Decision

Templates contain placeholders. First-write auth uses the shared credential
generator; hostlist and web.conf render from the effective account password
with an account salt and derived hash. Existing account credentials are kept.
The existing synchronized rotation path retries a legacy value immediately;
if it still cannot rotate, the watchdog does not start either Deluge service
and emits a structured event without secret contents. Account-home writes use
the checked user-file writer; bounded verified reads reject substituted files.
Only an absent auth file is rendered from the template. An existing auth file
keeps its other users when the localclient line is added or replaced, including
CRLF records. A refused existing file (including an oversized or hardlinked
file) is left untouched; a failed read never authorizes resetting its users.

## Consequences

Fresh accounts have no common template password. Accounts already using a
distinct service password keep that password. An account whose old shared
value cannot be rotated remains stopped until the next retry. The primary
account password remains separate and hash-only. The configuration pass writes
the account-owned auth, hostlist, and web files at mode 0600. The daemon and
Web UI still use their existing loopback ports and panel proxy.
