# ADR 0125: Account-owned vanilla Hermes on port C

Date: 2026-10-20
Category: architecture

## Status

Accepted for opt-in installation. No account receives a vanilla Hermes runtime
until its own Configure action completes.

## Context

ADR 0128 reserves port 846 for one account-owned vanilla Hermes window. Each
account UI gets its own service origin and certificate. Root does not execute
or write account-controlled code (ADR 0121). Hermes' own login gates its
loopback listener; a configured public URL engages it.

## Options considered

- Each account UI uses a separate service origin and certificate.
- A customer `custom.d` proxy fragment was rejected because it could replace
  generated routing and would need a path prefix Hermes does not support.
- A generated port-C route with an account-side on-demand launcher keeps
  account processes under the account UID and puts the UI at `/`.

## Decision

The Configure card starts a pinned Hermes install as the account. The release
tag, commit, installer hash and package lock hash are verified before executing
upstream code. A checkout changed by the customer is left alone. The account
uses a separate random dashboard password and random session secret in a 0600
file; its primary password is never reused. The selected provider key remains
in a private `.env` entry referenced from `model.api_key` by environment name.
`model.base_url` is the supplied HTTPS endpoint unchanged.

The root-owned billing record supplies the same sha16 service permalink as
ADR 0128. Port C is `https://<sha16>.mcx.fi:846/`. Its nginx and lighttpd
blocks are generated, independent of the Eternals host marker and enabled by
the account's completed install. A root-owned default server rejects unknown
names. The port-C Hermes proxy clears Authorization, forwards HTTPS scheme, omits
query strings from access logs, rejects `/api/cron/fire`, and applies an exact
Origin check to writes and upgrades. GET `/api/*` requires Fetch Site
`same-origin` or `none`. Hermes' own login remains the backend gate. The
launcher checks listener ownership and probes for a 401 before opening it;
an ungated instance is stopped. The separate Agentboard mount forwards its
bearer Authorization header because that fork authenticates every API request
with it. Both mounts keep the same port-C Origin and Fetch Site checks.
Port C has two fixed pre-login selector redirects because its two mutually
exclusive UIs share one origin. Each selector only sets the HttpOnly UI-choice
cookie and redirects to `/`; it does not proxy an API request or expose account
content. The choice cookie has a `__Host-` prefix, and the proxy removes backend
cookie Domain scope. The Hermes login assets are the other pre-authenticated
paths. The generated Hermes mount uses an account-owned socket under `.hermes`;
an account-owned relay checks the TCP listener's UID before each connection.

The launcher starts Hermes web UI or standard Agentboard on demand as the
account. Agentboard uses a private Unix socket and single-use, 60-second
tokens in `~/.config/pmss/ui/tokens/` (0700 directory, 0600 files, eight live
tokens maximum). The chosen interface has one port-C browser origin.
Installer verbs use one account lock whose descriptor is closed on exec; no
Hermes cron supervisor is installed. The install action writes a status file
for the Configure card's five-second poll. Certificate requests originate in
the account process. Panel logout and account password change rotate the
port-C session secret and stop its running backend.

## Consequences and limits

The Hermes backend still binds loopback TCP. The Hermes login is required even
when nginx routing is correct.
Its login cookies are scoped by host rather than port; the port-B and port-C
Hermes windows share one browser cookie jar. Signing in to one can sign the
other out, as Configure discloses.

Vanilla Hermes is opt-in. The managed transfer excludes `~/.hermes` from its
hot main pass, stops the account dashboard before the final pass, and copies
the tree then. A customer-started, untracked Hermes process can still write
during that final copy; the transfer cannot freeze arbitrary account code.
No root process copies or mutates that tree for this feature.

The pinned release was checked against published upstream advisories. An agent
session acts with the account's permissions and provider key. The public
endpoint therefore requires the dedicated login,
origin checks, a private secret file, and an account UID process. No root
credential is exposed to Hermes.

## References

ADRs 0121 and 0128; `docs/contracts.md`, `docs/hermes.md`,
`docs/portManager.md`.
