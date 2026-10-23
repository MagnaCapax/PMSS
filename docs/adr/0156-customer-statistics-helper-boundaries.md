# ADR 0156: Customer statistics helper boundaries

Date: 2026-10-23
Category: architecture

## Status
Accepted

## Context
The customer Info page loads a 687-line helper containing collection, service
status, traffic, resource projection, resource HTML and chart generation.
Four independent display fallback branches repeat the same saved-value policy.
Customer PHP must remain inside the delivered account tree (ADR 0016), and the
guiv page can arrive before its update-only helpers.

## Options Considered
- Keep the combined helper and its repeated fallback branches.
- Split by responsibility behind the existing include and share numeric fallback
  traversal, retaining the distinct CPU conversion contract.
- Replace the page or introduce a new rendering framework, expanding the contract.

## Decision
Keep `statsHelpers.php` as the stable facade over six local modules: `statsState`,
`statsStatus`, `statsTraffic`, `statsResourceModel`, `statsResourceRender` and
`statsCharts`. Check all module paths before loading any API, so partial delivery
uses the existing reload notice. The updater distributes modules before the facade.

Use one display-field map for the resource schema and projection, and one numeric
fallback loop for byte and RAM-hour displays. CPU retains its existing monthly
override and shorter-window duration conversion. Preserve function names, array
key order, unit boundaries, HTML and JavaScript bytes, and command behavior.

## Consequences
- Each responsibility has one implementation; the old combined implementation is
  removed. Four fallback branches become one traversal with a RAM-hour conversion.
- New files must be delivered together; preflight checks retain fail-soft rendering.
- Characterization tests lock resource shapes, saved-value precedence, numeric
  boundaries, chart bytes and serialized-state results. Panel tests cover each
  missing module. Tests execute on testing machines, never the control host.

## References
- [Customer PHP tree separation](0016-customer-php-tree-separation-from-operator-scripts.md)
- [Contracts](../contracts.md#customer-server-status--etcskelwwwwelcomephp-and-statsphp)
