# ADR 0199: Deluge configuration transformation boundary

Date: 2026-10-28
Category: architecture

## Status
Accepted under the operator-approved architectural simplification run.

## Context
Deluge provisioning mixed template selection, rendering, port adoption, file
publication and account convergence in one module. Managed maintenance mixed the
dual-JSON format with core safety settings and hostlist credential synchronization.
Core and hostlist updates independently decoded metadata and config, detected
changes, encoded both documents and decided whether to write.

## Decision
Keep both existing includes and all callable signatures. Separate template
rendering, provisioning I/O, the pure codec and hostlist policy into sibling
modules, each loaded by its existing facade. Both maintenance writers use
`pmssDelugeConfigTransform()` for decode, callback, change detection and encode.
Core writes retain explicit account ownership; hostlist writes retain existing
metadata. Their guarded readers and writers remain at those I/O boundaries.

The codec returns at the closing metadata brace instead of storing an end marker.
String escaping consumes one byte at a time; JSON decoding still validates each
complete document. Hostlist updates compare the transformed config through the
shared path instead of maintaining a separate changed flag.

Keeping separate transform controllers was rejected because format and no-op
decisions would remain duplicated. Combining the writers was rejected because
their ownership and mode contracts differ. Customer PHP keeps its own helpers
inside the customer tree (ADR 0016).

## Consequences
- Provisioning and maintenance each retain one controller and their old includes.
- Two transform pipelines become one; the metadata-end sentinel and hostlist
  changed flag disappear. No legacy operator codec or mutation path remains.
- Invalid input, callback rejection, encoding failure and unchanged config retain
  their no-write results; callback exceptions continue to propagate.
- Templates, managed values, port ranges, credential generation, modes, account
  ownership, restart order and user-visible output retain their contracts.

## Validation
`DelugeConfigCharacterizationTest` locks nested and escaped metadata, malformed
and truncated input, accepted array configs, duplicate keys, exact encoding bytes,
callback invocation and unchanged-file behavior. Existing managed-config,
credential, template and guarded-write tests cover the surrounding I/O policies.
Tests run only on testing machines. The source-routing assertion follows the
writer into `delugeProvisioning.php`, with its forbidden-operation checks retained.

## Risks
The reviewed [GitHub Security Lab Deluge advisory](https://securitylab.github.com/advisories/GHSL-2024-188_GHSL-2024-191_Deluge/)
documents WebUI file reads, SSRF and limited writes (CVE-2025-46561 through
CVE-2025-46564). Exposed Deluge configuration can disclose service credentials,
hashes or sessions and compromise the account's Deluge access. This restructuring
keeps the dedicated service credential, account credential separation, file
permissions, path guards and existing exposure boundaries. It adds no credential
logging, public endpoint, listener, service invocation or filesystem mutation.
