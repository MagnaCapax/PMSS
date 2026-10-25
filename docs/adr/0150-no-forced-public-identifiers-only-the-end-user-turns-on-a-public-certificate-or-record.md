# ADR 0150: No forced public identifiers — only the end user turns on a public certificate or record

Date: 2026-10-22
Category: security

## Status
Accepted (operator-directed, 2026-10-22). Supersedes the implicit-certificate clauses of ADR 0128.
Amended the same day: Decision 3 now keeps account features local, under the existing panel path.

## Context

Customer privacy is the product. Some things PMSS can do for an account cannot be taken back once done:

- A certificate from a public CA is written to Certificate Transparency logs. The names in it, the issue
  date and every renewal stay public forever, and anyone can search them.
- A DNS record or a file under a public path tells the world that an account, a service or a server
  relationship exists.

ADR 0039 made per-name HTTPS opt-in and rejected auto-issue. ADR 0128 then allowed "implicit" certificates for
the Pikkumies web UIs. As built, a status poll that runs when the panel loads, and the Hermes install, write the
account's certificate request without asking the customer. Every account that reaches "Pikkumies ready"
therefore gets a public certificate. A per-account hostname was chosen to give each account its own browser
origin. That requirement came from the design itself; no request for the feature contained it. Pikkumies is an
agent that runs inside the customer's account, and its web screens are pages of that account's panel, like the
browser console (ADR 0031) and OpenClaw (ADR 0087).

The operator ruled on 2026-10-22: "nothing should force enable Let's Encrypt personal keys or other PII leaks,
privacy leaks. only end user can choose to do that". The same day: "this doesn't require let's encrypt, the design
is fucked if it does", and "It is not to run externally but locally".

## Decision

1. **Only the end user turns it on.** PMSS never requests a public-CA certificate for an account, and never
   publishes any account-linked name, record or file, unless the end user takes an explicit action for that
   purpose. The action must say what becomes public and that it cannot be withdrawn. This covers certificates and
   their private keys, DNS records, public files, public URLs and any other identifier that can be traced to an
   account or customer.
2. **No side effects.** A page load, a status poll, an install, a default, an update, a migration, a cron job or a
   rollout never counts as that action. None of them may write a certificate request or publish such an
   identifier.
3. **Account features run locally.** A feature runs inside the account, on a unix socket or loopback, and is
   reached only through the existing panel path `/user-<name>/...` on the server's own FQDN and host certificate.
   It adds no public name, DNS record, certificate or listening port. A design that seems to need one is wrong,
   and the design changes.
4. **Allowed paths today.** The only writers of a certificate request are the HTTPS card in Configure (with its
   CT acknowledgement) and `createWebPublicCerts` (with its typed confirmation), per ADR 0039. Any other writer of
   `~/.request-web-certs` is a defect.

## Consequences

- ADR 0128's implicit certificates are superseded: its per-account service certificate, the launcher's certificate
  request and its quota note. The Pikkumies UIs move under the account's panel path, served locally through the
  account's web server; their per-account hostnames and ports 844-846 are removed. The launcher stops writing
  certificate requests and stops waiting for one.
- Rollout pacing never comes from a CA quota, because rollouts no longer issue certificates.
- Certificates already issued without the user's own action stay in CT logs and cannot be withdrawn. They are
  counted and reported. Their lineages are retired unless the user opts in.
- Records that already publish account-linked names without a user action conflict with this ADR. They get their
  own decision: the per-service and cluster `mcx.fi` records (ADR 0040).
- A test fails the build when any file outside the allowed paths in Decision 4 writes a certificate request.
- `scripts/testing/public-exposure-lint.php` fails the build on a new certificate, ACME or DNS path, a new public
  name derivation or a new nginx port. Adding to its pin lists needs the operator's ruling.
- In review, "it is only a hash" is not enough. A label derived from a sequential or guessable id can be
  reversed by enumeration.

## Considered options

- **Keep implicit certificates and pace the rollout by the CA quota.** Rejected: the CT entries are a permanent
  disclosure the customer never chose, and pacing only spreads it out.
- **Wildcard certificate.** Rejected per ADR 0039 (one key on many hosts).
- **Private CA.** Rejected per ADR 0039 (users would have to trust a PM root).
- **A per-account browser origin (own hostname or own port) for account UIs.** Rejected: it publishes or opens
  something new for every customer. Pages of all accounts on one server share the server's origin. That is a
  property of the whole platform, already accepted for the console and OpenClaw, and it is changed for the whole
  platform if ever, never by giving one feature per-customer names or ports.

## References

ADRs 0039, 0040, 0090 and 0128.

## Amendment 2026-10-24: Welcome shows the account its own service permalink

A customer asked to see their permanent address in the panel instead of looking it up in the client area.
The Welcome page's Connect card now shows the service permalink (ADR 0040) with a Copy button and a link to the
wiki page "Permalinks on PMSS". The page is served only to the logged-in account. It derives the name from the
home marker ADR 0152 keeps for display, writes nothing and publishes nothing; the DNS record already exists.
`welcome.php` is added to the R4 pin list in `scripts/testing/public-exposure-lint.php` as a display-only
caller. The open decision on whether the `mcx.fi` records stay on by default (Consequences, above) is
unchanged: if they become opt-in, this row must show only for accounts that turned the record on.
