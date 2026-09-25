# ADR 0067: One app catalog owns media-stack panel policy

Date: 2026-09-25
Category: architecture

## Status

Accepted under the operator-approved architectural simplification run.

## Context

The customer media-stack panel kept its six web apps in one definition list,
but repeated the same app set in a runtime label map, four authentication
predicates, and a secure-prerequisite switch. Adding or removing an app could
therefore update its URL while leaving its label, auth state, or secure-action
gate behind.

The panel runs inside the customer tree. ADR 0016 forbids moving these reads to
operator-only code, and ADR 0017 requires behavior coverage for customer PHP.

## Options Considered

- Keep the branches and add review reminders. Rejected: parallel decisions
  remain and review cannot make them converge.
- Add a second security-policy registry. Rejected: it creates another source of
  truth beside the existing app definitions.
- Share the operator watchdog catalog. Rejected: that crosses the customer and
  operator PHP boundary and couples independently delivered files.
- Extend the existing customer app catalog. Selected: one entry can own labels,
  URLs, install markers, secure prerequisites, and auth-reading policy.

## Decision

`pmssMediaStackPanelAppDefinitionsRead()` is the sole app-specific policy
catalog for the customer media-stack panel. Generic readers consume its fixed
metadata for installed-app detection, auth-state detection, secure-action
prerequisites, URLs, labels, and action allowlisting.

The catalog remains closed data: paths, auth modes, allowed values, and app IDs
are defined in code. Request data cannot supply a path, parser mode, SQL query,
or shell fragment. The fixed Autobrr ownership query and the Cloudplow
runtime-only label stay in code because they are not web-app catalog entries.

## Consequences

- Four app-specific auth functions, one prerequisite switch, and the duplicate
  runtime label catalog are removed.
- New web apps have one customer-panel policy entry instead of several branches.
- Existing customer-visible URLs, labels, auth outcomes, prerequisite gates,
  runtime details, action names, and command escaping remain unchanged.
- `MediaStackPanelTest::testAppPolicyBehaviorSnapshot()` locks the catalog's
  derived behavior across all six apps.

## References

- ADR 0016: customer PHP tree separation.
- ADR 0017: customer-tree PHP review checklist.
- `docs/install-media-stack.md`: panel auth-status and secure-action contract.
