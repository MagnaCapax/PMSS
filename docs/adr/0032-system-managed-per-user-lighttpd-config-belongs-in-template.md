# ADR 0032: System-managed per-user lighttpd config belongs in template.lighttpd, not ~/.lighttpd/custom.d/

Date: 2026-07-11
Category: architecture

## Status
Accepted

## Context
Per-user lighttpd config can be placed in two locations, both loaded by every
user's instance:

- **`etc/seedbox/config/template.lighttpd`** — the managed source that PMSS
  renders per user (with `##username` substitution) into each generated
  lighttpd config. Version-controlled, uniform across the fleet, operator-owned.
  Already holds the system-side per-user blocks: rutorrent cache-control,
  owncloud, webdav policy, and (ADR 0031) the browser console proxy.
- **`~/.lighttpd/custom.d/*.conf`** — per-user drop-in fragments, included by the
  template (line 58). In practice these are written by **user-opted features**:
  `install-media-stack.sh` writes `~/.lighttpd/custom.d/media-stack.conf` when the
  customer chooses to run it.

When ADR 0031 landed the console proxy, its fragment was placed in
`template.lighttpd`. ADR 0015 (superseded, and — verified 2026-07-11 — authored
by the autonomous dev-cron under the operator identity, not a human decision)
had suggested `custom.d/`. The operator resolved the direction: *"system side
configs should be in the main template, not the custom configs for the end
user."* This ADR records that as a general rule so it stops being re-litigated
per feature.

## Decision
**Ownership decides placement.** PMSS-managed config goes in `template.lighttpd`;
config the customer owns goes in `~/.lighttpd/custom.d/`.

Primary discriminator — **who owns and manages this fragment?**
- **PMSS owns it** — PMSS authors it, controls its lifecycle, ships it with the
  platform, and the customer is not expected to edit it → `template.lighttpd`.
  It is rendered per user via `##username` substitution; "same block for every
  user modulo substitution" is the normal shape.
- **The customer owns it** — it exists only because the customer opted into a
  feature, its content is customer-authored, or the customer is expected to edit
  it → `~/.lighttpd/custom.d/`.

Uniformity is a **secondary signal, not a separate rule**: PMSS-managed fragments
are typically uniform across the fleet (identical modulo `##username`); customer
fragments are typically per-user and variable. Uniformity follows from ownership —
it is a symptom, not the test. When the two conflict (a PMSS-managed fragment
whose content legitimately varies by plan/tier), **ownership wins**: it stays in
`template.lighttpd`, with the variation expressed through the template's
substitution/conditionals — not exiled to `custom.d/`.

## Consequences
- Positive: single source of truth for platform config; `##username`
  substitution and version control apply uniformly; review/CI covers it.
- Positive: keeps the customer-writable `custom.d/` tree for genuinely
  customer-owned config, preserving the operator/customer boundary.
- Trade-off (accepted): template blocks have fleet-wide blast radius — a syntax
  error breaks every user's lighttpd on regen. Mitigation: hermetic tests assert
  the block's shape, and template changes are validated (the ADR 0031 console
  block was proven to parse + serve WS-101 on lighttpd 1.4.59 before shipping).
  This trade-off is the reason the rule is explicit rather than "always minimize
  blast radius by using custom.d."

## References
- Operator directive, 2026-07-11: system configs in the main template, not custom.d.
- ADR 0031 (browser console — the triggering case), supersedes ADR 0015.
- `etc/seedbox/config/template.lighttpd` (system-side per-user blocks).
- `etc/skel/install-media-stack.sh` (`~/.lighttpd/custom.d/media-stack.conf` — user-opted example).
