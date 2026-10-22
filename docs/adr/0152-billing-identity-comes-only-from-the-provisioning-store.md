# ADR 0152: Billing identity comes only from the provisioning store

Date: 2026-10-22
Category: data

## Status

Accepted. Replaces the transition rule in ADR 0128 that kept home-marker
reads for accounts without a stored service ID.

## Context

An account's billing service ID and client ID decide which mcx.fi names nginx
serves for it, which names its public certificate covers, which WireGuard
endpoint its guide shows, which certificate termination retires, and which IDs a
support request carries.

Two copies of these IDs exist on a node. The root-owned provisioning store
(`/etc/seedbox/config/users/<user>.json`, with the older aggregate
`/etc/seedbox/runtime/users.json` as its fallback) is written by
`writeHomeMarker.php` and by provisioning. The marker files in the account home
(`.billingServiceId`, the older `.billingId`, and `.billingClientId`) are
written alongside it. The account home is account-controlled space (ADR 0121),
so its contents are not a dependable record of which service the account is.

ADR 0128 made the store authoritative but kept the home-marker reads for
accounts whose store record had no service ID yet, so that names stayed
served while the store was backfilled. This change is released only after a
census shows the store holds the service ID for every account that has one,
apart from named cases.

## Options Considered

- Keep the home-marker fallback indefinitely. Rejected: root-side decisions
  would keep depending on account-controlled space for any account that lacks
  a store value.
- Read only the store, and treat a missing store value as no billing identity.
  Chosen. The account keeps its per-server names; mcx.fi names, the mcx.fi
  certificate name and the service WireGuard endpoint wait for a store value.

## Decision

1. `pmssUserBillingIdentityRead($user, $key, $configDir, $requiredUid)` in
   `scripts/lib/user/billingIds.php` is the only reader of billing identity.
   It reads the per-user store record, then the aggregate store, and returns a
   positive digit string or null.
2. nginx user configs, `webPublicCertsProcess.php`, WireGuard guides, support
   diagnostics and `terminateUser.php` read through it. The home-file readers
   (`pmssUserBillingServiceIdDigitsRead`, `pmssUserBillingClientIdDigitsRead`,
   their integer forms, and `pmssNginxUserBillingServiceIdFromHome`) are removed.
3. Home markers stay. Provisioning, `recreateUser` and `userPermissions` keep
   writing and normalising them as before, so account-side tools in
   `etc/skel` (the Welcome page, Configure, `createWebPublicCerts`) can still
   show the account its own service ID, and the account-run `support` command
   can fall back to them when the store is unreadable. Those tools run as the
   account and cannot read the store (ADR 0016). They only display or report
   the value; root-side readers never use it.

## Consequences

- An account with a home marker but no store value loses its mcx.fi names and
  its mcx.fi certificate name at the next nginx regeneration or certificate
  run, until provisioning records the store value. The release waits until a
  census shows no such accounts apart from named cases.
- `userTransfer` targets get their identity from the target store, written by
  provisioning on the target, not from the target's `.billingId`.
- Support diagnostics carry the stored IDs when the caller can read the store.
  The `support` command runs as the account, which cannot, so it reports the
  account's own root-owned marker copy. A support request is the account's own
  report; no root-side decision uses these values.
- Tests cover the store value winning over home markers, home markers being
  ignored when the store has no value, the aggregate fallback, and value and
  entry validation.

## References

- [ADR 0016: Customer PHP tree separation](0016-customer-php-tree-separation-from-operator-scripts.md)
- [ADR 0040: mcx.fi cluster hostname serving](0040-mcx-fi-cluster-hostname-serving-two-gate-code-plus-data.md)
- [ADR 0092: Inode-safe account data movers](0092-inode-safe-account-data-movers.md)
- [ADR 0121: Root operations and account-controlled objects](0121-root-operations-and-account-controlled-objects.md)
- [ADR 0128: Pikkumies eternals account web UIs](0128-pikkumies-eternals-account-web-uis.md)
