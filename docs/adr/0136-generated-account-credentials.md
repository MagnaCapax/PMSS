# ADR 0136: Generate every account credential character from the CSPRNG

Date: 2026-10-19
Category: domain
Status: Accepted

## Context

The account reset command previously mixed a legacy seed with random middle
characters. Generated credentials are consumed by Unix login, the panel, and
qBittorrent. The existing generated character set is accepted by each reader.

## Options considered

- Retain the mixed generator. It provides inconsistent randomness across the
  credential.
- Draw every character independently from the existing character set. Chosen
  to give a consistent 16-character generated value without changing readers.
- Expand the character set. This would change established command-line and
  application input expectations without a demonstrated need.

## Decision

Use `random_int()` for all 16 characters. The first and last characters are
alphanumeric; middle characters may also contain `-` or `_`. Deluge service
credentials reuse the same generator and retain their existing length and
character set. Supplied account credentials remain unchanged.

## Consequences

New generated account credentials have a fixed length. Stored credentials and
the supplied-value path are unaffected. The helper is available to both account
and Deluge credential generation.
