# ADR 0179: Network setup policy and acquisition boundaries

Date: 2026-10-25
Category: architecture

## Status
Accepted

## Context
Network setup mixed uplink discovery, firewall construction and system changes.
Its firewall assembly staged six named intermediate groups before combining the
filter/NAT lists, and kept two names for the same uplink. The iptables library
mixed rule interpretation, monitoring discovery and command application.
The network policy itself must remain unchanged for existing accounts.

## Options Considered
- Keep the combined script and independently staged chain groups.
- Add configurable firewall backends or a second policy representation.
- Separate existing responsibilities and construct the final pair directly.

## Decision
Keep `setupNetwork.php` as the ordered entrypoint and `network/iptables.php` as
the stable application facade. `network/setup.php` owns uplink selection and
the ordered filter/NAT policy. `rules.php` owns parsing, rendering and validation;
`monitoring.php` owns owner-match discovery and checked helper output.
These internal modules are loaded by their entrypoints; they perform no work
at include time. Keep the existing legacy `net/iptables.php` delegating shim.

Build the filter list directly in its existing order: low-MSS protection,
bogons, ingress, overlay forwarding, overlay egress and monitoring. Retain NAT
order and both substitution tokens, mapping each token to the same uplink.
Remove the six named intermediate groups and the redundant uplink alias.
Serialize restore sections directly into the final payload instead of staging
a second section list. Traverse the filter/NAT pair in one fallback loop,
retaining the existing explicit NAT-prefix check. No alternate builder or
parser remains.

## Consequences
Rule selection no longer requires tracking six temporary groups. Uplink
discovery and rule construction can be characterized without invoking the CLI
entrypoint. Application retains its complete-write check, private temporary
file, cleanup on exceptions, validation before flushing, and ordered fallback.
All existing callable names and legacy include paths remain available.

The added module includes are required with the full repository snapshot,
as with the existing network libraries. There is no new deployment mode,
configuration switch, listener, public identifier or dependency.

## Security Review
The existing low-MSS rule relates to the remote denial-of-service family
CVE-2019-11477/11478/11479. Netfilter also has local privilege-escalation history,
including CVE-2021-22555 in x_tables. This refactor preserves the rule bytes and
does not change kernel mitigations or namespace policy.
Malformed shell fragments remain rejected before firewall application. A
mistaken policy change could affect every account's connectivity, so the full
ordered policy is locked by a literal snapshot, including the default Web UI
port drops and overlay isolation. Credential storage, credential logging and
secret-file permissions are outside the changed paths; no stored value is
introduced or exposed. Existing log destinations and restore-file permissions
remain unchanged. No live firewall or shaping command runs during development.

## Validation
`NetworkSetupCharacterizationTest` locks the full rule list, monitoring order,
uplink probe order, tunnel fallback, cap warnings and legacy failure stdout.
`NetworkIptablesCharacterizationTest` locks fallback commands/stdout and empty
or single-table restore payloads with isolated command and file stubs. Existing
write-failure, cleanup and compatibility tests remain intact. The source-level
port/safety assertions follow the moved code without removing any assertion.
Static checks run on the control host; execution tests run on testing machines.

## References
- [Function contracts](../contracts.md#networking)
- [Netflix TCP advisory](https://github.com/Netflix/security-bulletins/blob/master/advisories/third-party/2019-001.md)
- [Debian CVE-2021-22555 tracker](https://security-tracker.debian.org/tracker/CVE-2021-22555)
