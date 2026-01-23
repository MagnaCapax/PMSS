# ADR 0008: Reject deb822 migration for Debian apt sources (for now)

Date: 2026-01-23  
Category: architecture

## Status
Rejected (revisit 2030 or when fleet bulk is Debian 13+)

## Context

PMSS manages Debian repository configuration via templated `sources.list` content
(`etc/seedbox/config/template.sources.<suite>`) applied during the update flow.

Debian and Ubuntu introduced the deb822 `.sources` format as an alternative to the
traditional one-line `deb ...` entries in `sources.list`. We evaluated whether
PMSS should migrate its *base Debian repository templates* to deb822.

Constraints and current environment:
- Traditional `sources.list` format is expected to work through 2029+, with the
  earliest plausible removal being 2030.
- The fleet spans Debian 10–13, with the bulk on Debian 11 transitioning to 12.
- The current templated `sources.list` system has worked reliably for decades.
- Supporting and testing two template formats across distros would add ongoing
  complexity (rendering, validation, docs, operator knowledge, and incident
  response).

Research findings (negative):
- More verbose (multiple lines vs. single-line entries) and stricter parsing.
- A single typo can reject the entire file instead of ignoring a bad line
  (violates fail-soft doctrine and increases blast radius).
- Whitespace sensitivity introduces fragility.
- Error messages are often less specific than the one-line format.
- Tooling ecosystem maturity concerns (documented Ansible/Puppet module bugs).
- Ubuntu Bug #2110032: architecture conversion fails during upgrades.

## Options Considered

- Option A – Keep templated `sources.list` and improve key scoping in-place.
  - Pros: stable, familiar, minimal churn; aligns with fleet mix; preserves
    fail-soft behavior.
  - Cons: does not adopt deb822 feature set.

- Option B – Migrate Debian base templates to deb822 `.sources`.
  - Pros: aligns with newer distro direction; structured format.
  - Cons: new failure modes and stricter parsing; higher operational overhead;
    requires dual-path support for older fleet; adds complexity without a clear
    PMSS-specific win.

- Option C – Hybrid (deb822 on newer distros only; legacy on older distros).
  - Pros: incremental rollout potential.
  - Cons: permanently doubles template surface area and testing matrix; higher
    cognitive load during incidents; increases drift risk.

## Decision

Choose **Option A**.

PMSS will **not** implement a general migration of Debian base repository
templates to deb822 `.sources` format.

Security benefit (key scoping) is achievable without migration:
- Add `deb [signed-by=/path/to/key.gpg] ...` to existing `sources.list` entries.
- This binds each external repo entry to a specific keyring file without changing
  the base template format.

This decision does not require changing the already-shipped Docker deb822 setup;
it only rejects migrating the *main Debian base templates*.

Reconsideration triggers:
- Earliest revisit: **2030** or when the bulk of the fleet reaches **Debian 13+**
  (whichever comes first).
- Formal reconsideration: **2032** or when the bulk of the fleet reaches
  **Debian 14** (whichever comes first).

## Consequences

- Positive:
  - Avoids expanding the repo-template/test matrix during Debian 13 experimental
    validation.
  - Preserves fail-soft behavior (bad line is localized, not file-fatal).
  - Keeps operational and incident-response burden low.

- Negative:
  - Does not adopt deb822-specific features for base repos.
  - Requires ongoing awareness of key scoping and external repo hygiene.

- Follow-ups:
  - #TODO #Security: audit external repository entries (Docker, MediaArea, Sonarr,
    etc.) for `signed-by=` adoption where feasible, without changing the base
    template format.

## Operator Commentary

— Aleksi Ursin

> To my eyes this reads as added complexity with minimal to no gains whatsoever,
> appealing to a lower common denominator who prefers complex stuff over simple
> stuff. Sure there are going to be (maybe) edge cases where this is better than
> using the signed-by=xxxx method maybe? But seems like this is just one of those
> things where people wanted to add more complexity for no reason.
>
> Like "machine parseable format eliminating complex regex parsing" is a
> benefit... but even that is now minimal with LLMs being prevalent and being
> just a curl single liner away. It does add features, but at what cost?
>
> Like repo enabled? Just mv the source file if multiple. Multiple stanzas on a
> single line? For the added complexity vs. just copy & paste... (or add that
> [stable|stable-updates|stable-security] to old format — well some parsers will
> crap out, prefer not, breaking old users).
>
> This adds friction, and is more fail-hard, rejecting entire files than a bad
> line?!?!? Who designed this shit? Always fail forward / fail soft, that's our
> philosophy — do everything possible to continue and recover from issues.
> Whitespace sensitivity? What the fuck? Error messages failing observability
> minimums? That's greeeaaaaat (Office Space movie style).
>
> New failure modes, very little to gain for us if anything at all. This feels
> like systemd level of shenanigans — systemd still not IMO stable and not done
> human user / sysadmin first (context jumps around and other psychotic shit,
> makes stuff slower and more complex).

## References

- `etc/seedbox/config/template.sources.*`
- `scripts/lib/update/apt.php`
- `docs/update.md`
- Ubuntu Bug #2110032 (architecture conversion fails during upgrades)
