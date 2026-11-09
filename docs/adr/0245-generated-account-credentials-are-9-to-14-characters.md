# ADR 0245: Generated account credentials are 9 to 14 characters, length drawn at random

Date: 2026-11-09
Category: domain
Status: Accepted

## Context

ADR 0136 fixed generated account credentials at 16 characters. That length was
chosen by an agent, never by the operator, and every customer who got a
generated password had to read out and type 16 random characters for no
security gain. The operator set the level on 2026-11-09: about 10 characters,
and not a static length.

Every character comes from `random_int()` over the existing set: 57
alphanumeric characters without look-alikes at the edges, plus `-` and `_` in
the middle (59). One length L carries 2·log2(57) + (L−2)·log2(59) bits:

| Length | Bits |
|---|---|
| 9 | 52.8 |
| 10 | 58.7 |
| 11 | 64.6 |
| 12 | 70.5 |
| 13 | 76.4 |
| 14 | 82.3 |

Drawing the length uniformly from 9 to 14 adds log2(6) = 2.6 bits on top of
the length that is drawn. The weakest case, which an attacker would search
first, is a 9-character value: 52.8 + 2.6 = 55.4 bits of min-entropy. The
average (Shannon) entropy is 70.1 bits. Online guessing against sshd is capped
by the pam_unix failure delay, MaxAuthTries and MaxStartups: at a generous 100
guesses per second, searching half of the 9-character space alone takes about
1.3 million years. On Debian 11 and newer the shadow and htpasswd entries share
one bcrypt hash, so an offline attack on a leaked hash is slow as well.

## Options considered

- Keep 16 characters. Harder to read out and type, with no gain against any
  attack these readers face.
- A static 10 characters. Out of online reach, but the length is a known
  parameter.
- A random length from 9 to 14. Chosen, as the operator directed: about 11.5
  characters on average, and the length itself is unknown to an attacker.

## Decision

`pmssAccountPasswordGenerate()` without a length draws one uniformly from 9 to
14 with `random_int()`, then applies the ADR 0136 rules: every character from
`random_int()`, alphanumeric first and last characters, `-` or `_` allowed in
the middle. A caller may still pass a fixed length. Supplied credentials,
Deluge service credentials and stored credentials are unchanged.

This supersedes the length in ADR 0136 only. A generated customer password is
never made longer or more complex than this without the operator's decision.

## Consequences

New generated account credentials are 9 to 14 characters. Existing passwords
keep working. Readers (Unix login, panel htpasswd, qBittorrent) already accept
the value, since only the length changes.
