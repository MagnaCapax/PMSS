# ADR 0268: WireGuard peer-state responsibility boundaries

Date: 2026-11-11
Category: architecture

## Status

Accepted

## Context

The peer subsystem mixed configuration acquisition, block decoding, public-key
registry reads, address assignment and text rendering across `state.php` and
`peers.php`. Configured-owner and configured-key readers independently repeated
the same read/filter/deduplicate/sort workflow. Block parsing had three field
handlers; rendering retained a second line-list representation before joining.

These readers intentionally have different contracts. Owner comments match
case-sensitively and sort naturally; configured public keys match case-sensitively
and sort lexically. Block decoding accepts case-insensitive daemon field names
and incomplete records so status reporting and assignment can apply their own
eligibility rules. ADR 0135's live-address pins remain authoritative.

## Options Considered

- Keep the mixed modules and duplicate readers: fewer include files, but no
  reduction in independent workflows.
- Reuse the block parser for configured-owner/key discovery: would change
  matching, incomplete-record and validation behavior.
- Retain the established includes and separate the existing responsibilities,
  sharing only the configured-value reader workflow. Chosen.

## Decision

`state.php` owns configuration acquisition and the shared configured-value
reader. `peers.php` owns public-key validation and account registry reads.
`peerConfig.php` owns block parsing and peer rendering. `peerAssignment.php`
owns pin reservation and deterministic address allocation, moved unchanged.
The existing facade loads these definitions without performing provisioning.

Each configured-value reader supplies its exact regex, validator, result field
and sort flags. Block fields share one ordered dispatch table with the original
patterns and first-address normalization. Rendering accumulates the final text
directly, preserving empty-state messages, record order and trailing newlines.
No alternate implementation or compatibility path is retained.

## Consequences

The duplicate configured-value pipeline and intermediate rendered-line list
disappear. Existing public functions, result shapes, diagnostics, file guards,
key limits and assignment order remain intact. Two additional definition-only
modules must ship with the existing library tree; customer guide delivery gains
no dependencies.

`WireguardPeerStateCharacterizationTest` locks parsing, sorting, read refusals,
key validation, allocation exhaustion and peer text. Existing
`WireguardPeerAssignmentTest` cases cover pins, collisions, limits, duplicate
owners and failed reads. Execution belongs on testing machines; devbox
verification uses changed-file PHP syntax checks only.

## Security review

The reviewed CVE-2023-35838 record concerns Windows-client firewall routing;
it does not describe this Linux peer-state pipeline. Upstream documents roaming
redirection, handshake CPU pressure and identity exposure after a server private
key compromise. Peer/key/address associations therefore remain validated at
their existing boundaries. Public peer records identify accounts and addresses;
they contain no private key or primary login credential. Exposure alone does
not supply the private key needed to authenticate a peer.

This change adds no credential storage, command, diagnostic field, port, DNS
record or web endpoint. Account password handling and generated VPN keypairs
remain separate. Server private keys and customer guides retain mode 0600,
server configuration retains 0640, and the configuration directory retains
0750 through the unchanged provisioning helpers. The refactor preserves
existing diagnostic bytes and never reads or logs private key material.

## References

- [ADR 0135](0135-wireguard-client-address-pins.md)
- [WireGuard cryptokey routing](https://www.wireguard.com/#cryptokey-routing)
- [WireGuard known limitations](https://www.wireguard.com/known-limitations/)
- [CVE-2023-35838 record](https://www.cve.org/CVERecord?id=CVE-2023-35838)
