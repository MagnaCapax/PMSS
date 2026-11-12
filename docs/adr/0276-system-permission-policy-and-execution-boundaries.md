# ADR 0276: System permission policy and execution boundaries

Date: 2026-11-12
Category: architecture

## Status
Accepted

## Context
`scripts/util/setupPermissions.php` combined CLI exit handling, target checks,
provisioning-tree normalization and service-secret restrictions in 245 lines.
Directory/file predicates repeated the symlink guard, and separate command
runners expressed the same traversal with or without root ownership. Repeated
per-file blocks made the complete permission sequence harder to review.

## Options Considered
- Keep the monolith and its overlapping helpers.
- Introduce a general permission-plan interpreter with operation records.
- Separate existing policy phases and share their checked command traversal.

## Decision
Keep the root-only CLI and its accumulated command-result exit status.
`systemPermissionsTargets.php` owns symlink refusal, typed target checks,
root ownership and one ordered command runner. `systemPermissionsTrees.php`
owns provisioning-tree passes, direct modes, dead API artifact retirement and
the existing private-record sweep. `systemPermissionsSecrets.php` owns TLS,
OpenVPN and WireGuard restrictions after that broad sweep.

The directory/file predicates become one typed check; the two command runners
become one runner with the existing ownership policy. Repeated direct-mode,
OpenVPN-file and WireGuard-file operations become ordered rows. There is no
new plan format, alternate entrypoint or parallel policy implementation.

## Consequences
- Two overlapping mechanisms disappear: separate typed predicates and separate
  ownership/no-ownership command traversals. The file boundaries describe the
  existing phases, adding no execution alternative: net two fewer concepts.
- Runtime code decreases from 177 to 144 nonblank, noncomment lines across the
  original CLI and its three modules; total runtime file lines fall from 245
  to 187. Each new runtime module is at most 60 lines.
- Command bytes, descriptions, target types, symlink diagnostics, direct chmod
  modes and ordering stay fixed. Ownership failures still allow later commands;
  direct chmod/unlink failures remain best effort. WireGuard child checks still
  require its parent directory. The config sweep and private user records keep
  their existing implementation. Existing symlink/race boundaries are preserved.
- `SystemPermissionsFlowCharacterizationTest` locks the complete command/mode
  trace, failure at every command position, best-effort results, absent targets,
  file/directory mismatches, symlink refusal, root refusal and thrown commands.
  Source contracts follow the moved policy; the protected harness is unchanged.
- No permission command or dry run executes on the devbox. Local verification
  uses changed-file PHP syntax checks and deleted-symbol searches; execution
  belongs to the lane's testing machine and CI.

## Security review
This restructuring changes secret-file handling code organization, so the
operational-safety and security-testing doctrines apply. Review covered
[OpenVPN's 2.6.10 security history](https://github.com/OpenVPN/openvpn/blob/v2.6.10/Changes.rst)
(Windows service/installation trust issues),
[Certbot's published advisory index](https://github.com/certbot/certbot/security/advisories),
its [private-key permission contract](https://eff-certbot.readthedocs.io/en/stable/using.html#where-are-my-certificates),
and [WireGuard's documented limitations](https://www.wireguard.com/known-limitations/).
These references inform the review; this change claims no CVE remediation.

The relevant local attack vector is disclosure or replacement of service keys.
Exposure can compromise TLS/VPN service identity; a CA or ACME account key has
a wider issuance blast radius. The runner reads no key contents and logs only
the same fixed paths and command descriptions. Primary account credentials and
service credentials remain separate and untouched; key modes and ownership
remain identical. No SSH, panel, API, listener, bind, certificate or DNS behavior
is added or changed. Direct symlinks retain their refusal and recursive commands
retain their exact scope. The characterization fixture records actions only.

## References
- [Provisioning-tree permission policy](0043-provisioning-tree-file-permissions-committed-modes-and-deploy-time-policy.md)
- [Private config records](0132-config-record-permissions.md)
- `scripts/lib/tests/development/setupPermissionsLocalnetTraversalContractTest.php`
