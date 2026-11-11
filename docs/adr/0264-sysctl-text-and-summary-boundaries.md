# ADR 0264: Sysctl text and hardware-summary boundaries

Date: 2026-11-11
Category: architecture

## Status

Accepted

## Context

The sysctl pipeline already distinguishes hardware detection, settings policy,
and managed publication. Its settings file also owned assignment parsing,
override filtering, rendering and change reports; its publication controller
also owned the hardware JSON projection. Change reports and hardware summaries
independently traversed grouped settings, with different duplicate-key behavior.

## Options Considered

- Retain the mixed responsibilities and independent traversals.
- Build a second flattened settings model for every consumer.
- Retain the existing includes and helpers, isolate text and summary work, and
  share an ordered value traversal without materializing it for change reports.

## Decision

Choose the third option. `sysctlSettings.php` retains the policy builders and
loads `sysctlText.php`, preserving its original callable surface. Text parsing,
filtering, rendering and change descriptions belong to that module.
`sysctlTuning.php` retains managed-file publication and execution order, loading
`sysctlSummary.php` for the existing hardware-summary writer.

`pmssSysctlSettingsValues()` yields string keys and values in encounter order.
Change reporting consumes every occurrence, comparing against the original
file; summary publication materializes the traversal, retaining the last value
of repeated keys before sorting. Non-array groups remain ignored by these two
consumers. Override filtering updates its local grouped value directly and
drops fully overridden, empty and malformed groups without a second output map.

Hardware discovery retains its override vocabulary and fallback policy. One
lookup replaces the separate true/false token sets, including the null result
for unset, empty and unrecognized values. NIC discovery reads the first valid
default route directly; a missing or malformed speed still returns 1000 Mbps
without trying a later route. Fast-swap policy reads the VM fact directly.

## Consequences

The five existing responsibilities have focused owners without another settings
schema or a parallel implementation. Four mechanisms disappear: one duplicate
grouped-value traversal, one separate boolean-token set, the NIC selection
sentinel phase, and the filter's second output map. Module headers and includes
nearly offset the executable-code reduction in physical runtime lines.

All sysctl values, validation, override precedence, warning bytes, writer guards,
summary fields, baseline/summary/modules-load ordering and reload gates retain
their existing contracts. ADRs 0026, 0114 and 0202 continue to govern policy.

`SysctlPipelineCharacterizationTest` locks duplicate precedence, group/key order,
scalar conversions, comment policies, exact rendered text, summary peer fields,
override vocabulary and first-route behavior. Existing `SysctlProfileSnapshotTest`
retains complete baseline hashes, memory boundaries and optional kernel settings;
`SysctlBaselineTest` retains managed-write and reload coverage. Execute tests on
the testing VM; devbox verification is changed-file PHP syntax only.

## References

- [Update workflow](../update.md)
- [Port reservation](0026-kernel-ephemeral-port-reservation.md)
- [IPv4-only policy](0114-ipv4-only-hosts-ipv6-disabled.md)
- [Memory reserve floor](0202-vm-min-free-kbytes-baseline-floor.md)
