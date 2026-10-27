# ADR 0196: ruTorrent RPC compatibility catalog boundaries

Date: 2026-10-27
Category: architecture

## Status
Accepted

## Context
ADR 0146's catalog combines settings aliases, call adapters, erasedata commands
and HTTP trust rules. Eight fixed setters duplicate their before/after bodies;
alias generations and call adapters also repeat common source fragments.

## Decision
Keep the public include, markers and ordered catalog API. Four focused helpers
own settings, call adapters, erasedata and HTTP actions. Compose alias generations
from shared prefixes and call adapters from one body with explicit scope/return
lines. One transformation changes the trust argument for eight explicit setters.
Retain separate property-allowlist and client-getter rules. Preserve all 18 triples
byte for byte, including erasedata's position between call adapters and actions.
Keep the existing per-patch writer, metadata, no-follow reads and home boundary.
Moving the monolith alone retains duplication; a second patch engine adds concepts.

## Consequences
Eight setter replacement bodies become one rule (-7); four catalog helpers add
four concepts (+4), net -3. Shared aliases and call bodies remove further copying.
Every new module is under 150 lines. Vendor files and provisioning are unchanged.
A serialized-catalog SHA-256 snapshot captured on a testing machine locks bytes
and order. Existing tests cover both generations, reconstruction, repeat-run inode
stability, unsafe/missing targets and RPC trust boundaries. Run tests only remotely.

## Security review
The CVE/attack-surface lookup consulted [upstream security releases](https://github.com/Novik/ruTorrent/releases)
and [RPC guidance](https://github.com/rakshasa/rtorrent-doc/blob/master/RPC-Setup-XMLRPC.md):
pass-through filtering and nested commands matter because RPC can execute as the
daemon account. This catalog stores no credentials and adds no logging or endpoint.
Credential separation, secret modes, account-side writes, parameter escaping and
erasedata quoting retain their existing owners; the snapshot locks trust arguments.
