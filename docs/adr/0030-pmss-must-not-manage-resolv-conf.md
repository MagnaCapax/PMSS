# ADR 0030: PMSS must not manage /etc/resolv.conf

Date: 2026-06-26
Category: architecture

## Status
Accepted (2026-06-26). Supersedes and rejects the reverted "converge resolv.conf onto PM resolvers"
attempt (commit cf9f05ec, reverted by 95712f33) which is the negative example for this decision.

## Context
PMSS is a **portable Debian overlay** that can run on ANY host — including third-party / rented
servers (e.g. a Hetzner box) that are NOT on Pulsed Media's network. A 2026-06-26 change added
`pmssBootDefaultsEnsureResolvConf()` which forced PM-internal resolvers `185.148.1.2/.3` into
`/etc/resolv.conf` on every PMSS host on each update. That was the WRONG LAYER: a PMSS install on a
non-PM-network host would have its DNS continually rewritten toward unreachable resolvers ("someone
rents a Hetzner server and tries to run PMSS there ... but can't because the nameservers crash
constantly" — operator). A reachability guard masked the breakage on PM hosts but did not make the
layer correct.

## Decision
**PMSS MUST NOT define `/etc/resolv.conf` content, and MUST NOT hardcode network-specific resolver IPs
(or any site-specific network assumption).** DNS resolver configuration belongs to the
host / network / OS-install / provisioning layer:

- PM's own hosts get their resolvers from **NOC-PS provisioning profiles** (`tools/nocps/*`), the OS
  install, or the degraded debootstrap-from-rescue SOP — not from PMSS.
- A third-party PMSS install uses whatever DNS the host operator configured.

PMSS stays resolver-agnostic and portable. This generalises: PMSS does not bake in any PM-network-
specific addressing.

## Consequences
- PMSS remains installable and correct on ANY network (MISSION #6 sovereignty/self-reliance; the
  portable product is the value — MISSION #5).
- The triggering Xeovo `.73` dedi DNS issue is fixed at the **dedi BUILD layer** (its non-standard
  debootstrap-from-rescue build skipped the standard resolv.conf that NOC-PS profiles already set),
  NOT in PMSS.
- Reviewer rule: any PR adding resolver/IP/hostname/network-specific config into the PMSS overlay is
  rejected by this ADR. Layer-check test: "would this break a PMSS install on a rented box on someone
  else's network?" If yes → wrong layer.

## Origin
Operator 2026-06-26 (Telegram), after catching the reverted change:
"PMSS ei pitäisi määrittää resolv.conf sisältöä !! Väärä vitun taso ... Joku vuokraa hetzner palvelimen
ja yrittää ajaa PMSS siellä ... mut ei voi koska nimipalvelimet kosahtaa kokoajan."
Antipattern: WRONG_ARCHITECTURAL_LEVEL_FIX.
