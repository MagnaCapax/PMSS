# ADR 0338: Per-account opt-in for buffered lighttpd proxy responses

Date: 2026-11-23
Category: domain
Status: Proposed

## Context
The managed per-user lighttpd template sets `server.stream-response-body = 2`
(stream, minimal buffering). A customer measured proxied responses from a local
backend at a few MB/s under mode 2 against several times that under mode 1 (one
loopback test on a busy shared host, not reproduced by staff). The value is
server-scope in a root-owned config, so an account cannot change it.

Mode 1 buffers responses into `server.upload-dirs`, which is
`/home/<user>/.lighttpd/upload`: extra I/O on the shared `/home` disk, counted
against the account's quota, and not relocatable (WebDAV PUT needs it there).

## Options Considered
- Change the host default to 1: moves the spool cost to every account on the
  strength of one measurement.
- A marker file under `~/.lighttpd/`, default unchanged: only the account that
  wants speed pays. Same shape as the WebDAV `webdav.www-writable` marker.

## Decision
`~/.lighttpd/stream-response-body.buffered`, a regular file owned by the
account in its own `.lighttpd` directory, makes the root renderer emit
`server.stream-response-body = 1` for that account. The default and
`server.stream-request-body` stay 2. The marker is account-writable and the
renderer runs as root, so it is checked without following links (a link must
not reveal whether another path exists) and its content is never read. A
directory, a link, a missing home or an invalid name keeps the default.
Removing the marker restores 2 at the next render.

## Consequences
- An account hit by slow proxied downloads has a self-service fix; nobody else changes.
- An opted-in account spools into its own quota and the shared `/home` disk. The
  report says lighttpd falls back to another upload dir on ENOSPC but not
  EDQUOT, so at hard quota large responses may fail; unverified.
- Follow-up: measure the A/B on a quiet and an I/O-saturated host, and fetch a
  large proxied response at hard quota, before recommending the marker or
  changing the host default.

## References
Refs #1205. Related: ADR 0032 (managed per-user lighttpd config).
