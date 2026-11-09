# ADR 0245: Generated account credentials are 10 characters

Date: 2026-11-09
Category: domain
Status: Accepted

## Context

ADR 0136 fixed generated account credentials at 16 characters. The length
followed the NIST SP 800-63B-4 15-character minimum, which applies to
human-chosen single-factor passwords. These credentials are machine-generated:
every character comes from `random_int()` over the existing set (57
alphanumeric characters without look-alikes, plus `-` and `_` in the middle).

A 10-character value from that set carries about 58.7 bits: 8 middle
characters at log2(59) and 2 edge characters at log2(57). Online guessing
against sshd is capped by the pam_unix failure delay, MaxAuthTries and
MaxStartups; at a generous 100 guesses per second, half that space takes
about 1.2e8 years. On Debian 11 and newer the shadow and htpasswd entries
share one bcrypt hash, so an offline attack on a leaked hash is slow as well.

The operator set 10 characters as the production length on 2026-11-09.

## Options considered

- Keep 16 characters. About 94 bits, more than any attack on these readers
  needs, and harder to read out and type.
- 10 characters from the same set. Chosen: out of online reach, easier to
  type, no change to readers or the character set.
- Fewer than 10. Below the level PM uses as its random-password baseline.

## Decision

`pmssAccountPasswordGenerate()` generates 10 characters by default, with the
ADR 0136 rules otherwise unchanged: every character from `random_int()`,
alphanumeric first and last characters, `-` or `_` allowed in the middle.
Callers may still pass a length. Supplied credentials, Deluge service
credentials and stored credentials are unchanged.

This supersedes the length in ADR 0136 only.

## Consequences

New generated account credentials are 10 characters. Existing passwords keep
working. Readers (Unix login, panel htpasswd, qBittorrent) already accept the
value, since only the length changes.
