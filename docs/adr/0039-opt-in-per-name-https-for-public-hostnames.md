# ADR 0039: Opt-in per-name HTTPS for public hostnames

Date: 2026-07-31
Category: security

## Status
Accepted (issuance path requires on-host verification before fleet enablement — see Consequences)

## Context
A user's public web folder is reachable by several stable hostnames — the
per-server subdomain `user.server.pulsedmedia.com`, the portable per-service
permalink `<sha16>.mcx.fi`, and (ADR-adjacent) the cluster permalink. Every one
of them is served over plain HTTP: opening `https://` warns, because the vhost
presents the **host** certificate (`CN=<server-fqdn>`), which does not match the
requested name. Verified live 2026-07-30: `https://<sha16>.mcx.fi/` and
`https://<user>.<server>.pulsedmedia.com/` both fail name validation against the
host cert.

Let's Encrypt approved a rate-limit increase (2026-07-16) to 1,000–3,000
certificates per registered domain for `mcx.fi` and `pulsedmedia.com`. That
raised a **quota**; it deployed nothing. Auto-issuing a certificate for every
name of every account would still be wasteful — ~2,331 services × multiple names
across two registered domains — and most accounts never share a public link.

## Decision
Per-name HTTPS is **opt-in**, issued on request, one certificate per user
covering their single-homed public names as SANs. Three mechanism changes plus
an issuance path:

1. **Per-user certificate selection** (`scripts/lib/nginxUserHosts.php`
   `pmssNginxUserSslBlock`, wired in `nginxConfig/userConfigsGenerate.php`): the
   public subdomain vhost uses `/etc/letsencrypt/live/<user>.<server>/` when that
   certificate exists, else the existing host cert. This changes nothing for a
   user who has not opted in. The unreachable private hash vhost was retired;
   see `docs/contracts.md` for the current subdomain contract.

2. **ACME HTTP-01 challenge location** (`nginxConfig/templates.php`, public
   subdomain port-80 block): `location ^~ /.well-known/acme-challenge/` served
   from the root-owned webroot `/var/www/acme-challenge`. certbot runs
   `--webroot` so it never parses-and-patches the generated config (ADR 0036).

3. **Customer opt-in** (`etc/skel/bin/createWebPublicCerts`): runs as the
   customer, records a `.request-web-certs` flag in their home, and reports their
   public URLs. It cannot issue the certificate — issuance needs root — so it
   only signals intent. This is the AGENTS.md boundary pattern (customer writes a
   readable artifact; a root job reads it) applied in the request direction.

4. **Root issuance job** (`scripts/cron/webPublicCertsProcess.php`, scheduled in
   `root.cron` every 5 min, no-op when nothing is pending): for each flagged
   user it runs `certbot certonly --webroot` for `<user>.<server>` and
   `<sha16>.mcx.fi`, regenerates only that user's config
   (`createNginxConfig.php --user <user>`), and activates the batch with a single
   graceful `nginx -s reload` gated on `nginx -t` — never a full `systemctl
   restart nginx`, which would drop every customer's in-flight transfer on the
   node for one customer's opt-in. A failed issuance writes a `.failed` marker and
   the user is skipped for 6h, so a broken request cannot hammer Let's Encrypt.

### Deliberate exclusions
- **Self-signed default: not built.** A self-signed cert produces the same
  browser interstitial as today's host-cert name mismatch, so it buys no UX
  change while costing a keypair, a file and an expiry surface per name. The
  existing host-cert fallback already lets `listen 443 ssl` start; opt-in
  upgrades it to a trusted cert. (This reverses the initial "self-signed by
  default" sketch after a least-wasteful review.)
- **Renewal wiring: not added.** `/etc/cron.d/certbot` (installed by
  `certbotSetup.php`) already runs `certbot renew` twice daily and renews every
  certificate on the host, including these. Adding renewal here would duplicate it.
- **Cluster permalink HTTPS: out of scope.** The cluster name is a round-robin
  multi-A record, so an HTTP-01 challenge can be answered by any of the
  customer's nodes, not reliably the issuing one. The issuance job excludes it.

## Consequences
- Inert until a customer opts in AND the fleet runs the updated templates/cron.
- No customer certificate is ever read from a customer home; certs are the
  standard root-owned certbot output.
- The **issuance job makes live ACME calls that cannot be exercised in the dev
  repo** (no host, no real Let's Encrypt). It must be verified on a real host —
  HTTP-01 reaches `/.well-known/acme-challenge/`, `certbot certonly --webroot`
  succeeds for the two names, and the regenerated vhost serves the new cert —
  before the cron is relied upon fleet-wide. The mechanism pieces (1, 2) are
  covered by `AddUserNginxConfigVerificationTest` and are independently safe.

## Alternatives considered
- **Self-signed by default** — rejected, see Deliberate exclusions.
- **`certbot --nginx`** (as the host cert uses) — rejected: the nginx plugin
  edits the live nginx config, conflicting with ADR 0036's generated-from-template
  rule and racing with `createNginxConfig`. `--webroot` keeps certbot out of the
  config.
- **Auto-issue for all names** — rejected: wasteful, and strains the LE
  per-registered-domain rate limit for a feature most accounts never use.

## References
- `scripts/lib/nginxUserHosts.php` — `pmssNginxUserSslBlock`
- `scripts/lib/nginxConfig/userConfigsGenerate.php` — per-user ssl block wiring
- `scripts/lib/nginxConfig/templates.php` — ACME challenge location
- `etc/skel/bin/createWebPublicCerts`, `scripts/cron/webPublicCertsProcess.php`
- ADR 0036 (generated-from-template), ADR 0038 (/~username/ alias)

## Amendment 2026-10-07: username-free default

Every publicly trusted certificate is recorded in public Certificate
Transparency logs, which are searchable and permanent. The per-server
subdomain contains the account username. Usernames are not public unless the
customer chooses to publish them.

The default request now issues a certificate only for the username-free
`<sha16>.mcx.fi` service permalink. The customer can include
`<user>.<server-fqdn>` only by selecting `--with-username-hostname` and typing
`yes` after the disclosure. The `.request-web-certs` file carries `default` or
`username`; unreadable and older request content selects the default. The
certbot lineage keeps the existing `<user>.<server-fqdn>` label in both modes:
that label is a local directory name, not a certificate name, and nginx uses
it to find the certificate. The customer CLI is now refreshed into existing
homes on update.

Existing certificates remain in place until a customer makes a new request.
Their names are already in the public logs; renewal reveals nothing new, while
removal would break working HTTPS. In default mode, HTTPS on
`<user>.<server-fqdn>` still shows a name mismatch because nginx serves the
permalink certificate. Switching modes repeatedly is bounded by the CA's
five-per-exact-name-set weekly limit. On-host verification that certbot replaces
the names of an existing lineage non-interactively is still required before
relying on mode changes.

A shared wildcard certificate per server or for `mcx.fi` was rejected. It
would copy one private key to many hosts; a key leaked from any host would let
an attacker impersonate every name covered by that wildcard.

References for this amendment: `scripts/lib/nginxUserHosts.php`
`pmssWebPublicCertNames`, `scripts/cron/webPublicCertsProcess.php`,
`etc/skel/bin/createWebPublicCerts`, and
`scripts/lib/update/users/filesystem.php`.
