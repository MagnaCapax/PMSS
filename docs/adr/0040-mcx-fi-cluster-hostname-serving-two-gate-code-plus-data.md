# ADR 0040: mcx.fi cluster-hostname serving is a two-gate (code + per-user data) feature

Date: 2026-08-04
Category: architecture

## Status
Accepted

## Context
Each customer with 2+ active services gets a stable **cluster** permalink
`substr(sha256("mcx.fi:customer:".clientId),0,16).".mcx.fi"`, published by the ns0
mcx.fi zone builder as a multi-A round-robin across the customer's node IPs. For a
node to actually SERVE that hostname (rather than answer with its default vhost), the
per-user public nginx vhost must carry the cluster hostname in `server_name`.

The sibling per-**service** permalink (`mcx.fi:service:<serviceid>`) already serves,
because the service id is derivable on the node from the legacy `.billingId` file that
provisioning has always written. The cluster hostname needs the **client id**, which is
a different datum: `userConfigApply.php` hardcodes `billingClientId => 0` and nothing in
provisioning ever writes `.billingClientId`. So the serving code alone is inert — it
computes a hostname from a value that is absent on the fleet (verified 2026-07-31:
`.billingClientId` on 0 of 19 homes on a live cluster node).

A prior attempt (reverted commit `664d8376`) tried to solve this with a remote
`pulsedmedia.com` API returning a per-server hostname→user slice. That was the wrong
layer: the node already holds each user's ids locally; no remote lookup is needed.

## Options Considered
- **A — Remote API slice** (the reverted `664d8376`): node fetches its cluster
  hostname map from a pulsedmedia.com endpoint. Cons: new endpoint, new auth surface,
  new failure mode, extra per-regen network dependency; solves a problem the node
  doesn't have (it already knows its users' ids). Rejected.
- **B — Compute locally from `.billingClientId`** (chosen for the code): a local
  function `pmssNginxUserMcxClusterHostname($billingClientId)` mirroring the existing
  per-service one, appended to the public vhost `server_name`. Needs `.billingClientId`
  present on the node.
- **C — Thread client id through order-time provisioning** to populate
  `.billingClientId` for new customers. Necessary for NEW customers, but the order-time
  chain (WHMCS pmseedbox module on web5 + sbautomage on hallinta) is infra-gated and
  does not retroactively fill existing homes.
- **D — Backfill `.billingClientId` for existing customers from authoritative WHMCS
  data** (management-side), since client id is recoverable per-service. Complements B+C
  by covering the existing fleet without touching the infra-gated order-time chain.

## Decision
Cluster-hostname serving is a **two-gate feature, per node**:
1. **Code gate** — `pmssNginxUserMcxClusterHostname()` in
   `scripts/lib/nginxUserHosts.php`, wired in `nginxConfig/userConfigsGenerate.php`
   (Option B). Rolls out via `update.php`.
2. **Data gate** — `.billingClientId` present in the user's home. Read via the canonical
   `pmssUserBillingServiceIdDigitsRead`-style reader; NO fallback exists for the client
   id (unlike the service id's `.billingId` fallback), so it must be explicitly written.

The client id is populated by TWO complementary paths: **order-time** for new customers
(Option C, infra side, tracked separately) and **backfill** for the existing fleet
(Option D — a management-host tool that recovers each user's client id from authoritative
WHMCS keyed by the `.billingId` already on the node). The backfill DELETES the need to
retrofit the order-time chain for existing customers.

Single-service users get a cluster `server_name` entry that never resolves in DNS (the
builder only emits a cluster record for 2+ services) — harmless: nginx simply never
receives a request for it. No guard needed.

## Consequences
- **Positive:** no API/auth/remote-slice surface (Option A's costs avoided); the node is
  self-sufficient; existing customers are covered without the infra-gated order-time
  change; round-robin spreads load across the customer's nodes.
- **Negative / trade-offs:** two independent gates mean "code deployed" ≠ "serving" —
  a node updated before the data is backfilled (or vice-versa) does not serve, which is
  a real diagnosis trap. Round-robin has NO health-check/failover: a down node in the
  rotation fails ~1/N of cluster requests until the next zone rebuild removes it. HTTPS
  on the cluster name is out of scope (multi-A defeats HTTP-01; see ADR 0039).
- **Follow-ups:** new-customer order-time `.billingClientId` write (infra); make the
  backfill a periodic reconciler if new-customer coverage lags; the affiliateId datum is
  the same class of provisioning gap and should ride the same path.

## References
- Serving code: PMSS `d69e4eca`; reader `scripts/lib/user/billingIds.php`
- Reverted wrong-layer attempt: `664d8376` (remote API slice), reverted `4f6c7e81`
- Backfill tool (sysadmin repo): `tools/dns/backfill-mcx-billing-client-id.php`
- Sibling ADRs: 0038 (/~username path), 0039 (opt-in per-name HTTPS)
- mcx.fi zone builder + address scheme: sysadmin `tools/dns/MCX-STABLE-ADDRESSES-README.md`
