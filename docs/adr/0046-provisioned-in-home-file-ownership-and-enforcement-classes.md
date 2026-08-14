# ADR 0046: Provisioned in-home file ownership and enforcement classes

Date: 2026-08-14
Category: architecture

## Status
Accepted

## Context

PMSS writes several kinds of files below a user's home directory. The sources
include the skeleton tree, ongoing user-file delivery, GUI recovery, rendered
per-user configuration, quota snapshots, traffic state, and resource data.
These files do not all have the same owner, mutability, or security role.

The distinction is currently expressed by several hand-maintained lists and
writer call sites. For example, `scripts/util/userPermissions.php` has separate
chmod, chown, and ownership-exclusion lists, while the shared serialized-file
writer applies root ownership, a per-user group, and optional immutability.
Traffic and resource writers use that shared path, but their classifications
are still implicit in each caller. This makes omissions such as the historical
`.resourceData` exclusion easy to introduce.

The proposal to make every remotely provisioned file root-owned and read-only
is too broad for PMSS. Supported customer-owned override surfaces must remain
writable, and a blanket world-readable mode would conflict with the
multi-tenant privacy boundary. Conversely, ownership alone is not enough for
files where a user could alter accounting or enforcement state.

The existing decisions in ADR 0016, ADR 0022, ADR 0032, ADR 0041, and ADR 0043
establish related boundaries but do not provide one vocabulary for all
provisioned in-home paths.

## Decision

PMSS adopts three classes for provisioned paths inside a user's home. The
classification is per path and per lifecycle contract, not inferred from a
filename extension or from the fact that PMSS can write the file.

| Class | Ownership and access contract | Immutability contract | Examples |
| --- | --- | --- | --- |
| **Authoritative** | PMSS owns the bytes and lifecycle. The file is root-owned. The user may read it only when the consuming service requires that access; user and other write access are prohibited. The group is the user's group only when that is the required read channel; otherwise `root:root` is valid. | Not implied. A file that PMSS must replace during an update or regeneration remains mutable to its trusted writer. | Rendered lighttpd configuration, managed shell configuration, and other PMSS-owned templates. |
| **Enforced** | A stricter authoritative path whose contents affect accounting, quotas, limits, or another tamper-sensitive control. It is root-owned, grants only the required read access, and denies user/other writes. | The trusted writer clears immutability before an atomic replacement and reapplies it afterward, where the filesystem supports the mechanism. | Traffic state and resource snapshots used for enforcement or metering. |
| **Customer-editable** | The customer owns the resulting state and may write it. PMSS may seed or reconcile it only through a documented, path-specific contract that preserves supported customer changes. | Must not be made immutable by the umbrella policy. | Supported lighttpd drop-ins, customer overrides, and customer data. |

The following rules apply to every class:

1. **Ownership is the primary discriminator.** A file is authoritative when
   PMSS controls its content and lifecycle; it is customer-editable when the
   customer controls the supported state. Fleet-wide uniformity is useful but
   does not override ownership.
2. **Least privilege is the default.** “Read-only” means no write permission
   for the customer or other tenants. It does not mean world-readable. Modes
   are selected from the actual consumer contract, normally using the user's
   group for customer reads and `root:root` when no customer read is needed.
3. **Immutability is an enforcement control, not a general provisioning
   default.** It belongs only to the Enforced class. Writers must use the
   shared clear-write-reapply path; they must not scatter independent
   `chattr` sequences across provisioning and maintenance code.
4. **Text redirects are optional guidance, not a security control.** When an
   authoritative text file has a supported editable counterpart, a concise
   managed-file header may direct the customer there. Binary and data files do
   not need, and must not receive, artificial comments.
5. **Recovery and update paths must preserve the class.** Provisioning,
   ongoing updates, GUI healing, and repair utilities may not silently change
   a path from authoritative or enforced to customer-editable, or the reverse.
   A deliberate reclassification requires a separate reviewed change.
6. **Unsupported filesystem enforcement is not success.** The Enforced class
   describes an intended tamper-protection contract; implementations must
   keep failures to apply that protection observable rather than treating an
   unavailable immutable flag as equivalent protection.

The current scattered lists remain behaviorally authoritative until a separate
implementation task introduces a single registry and migrates all writers and
permission repair code to it. This ADR records the policy; it does not itself
change ownership, modes, immutable flags, or customer data.

## Options considered

- **A — Root-own and make every provisioned file read-only.** Rejected. This
  removes supported customer customization and incorrectly treats data,
  configuration, and recovery surfaces as one class.
- **B — Root-own every file and make it world-readable.** Rejected. Customer
  readability must be explicit; world access is not a safe default on a
  multi-tenant host.
- **C — Keep per-file decisions without shared terminology.** Rejected. The
  existing split lists and writer call sites have already drifted.
- **D — Three classes with ownership-first rules and a future registry.**
  Chosen. It preserves existing supported surfaces, gives enforcement state a
  distinct contract, and provides one basis for future convergence work.

## Consequences

### Positive

- Reviewers have a single vocabulary for deciding whether a provisioned path
  is PMSS-authoritative, enforcement-sensitive, or customer-owned.
- The policy preserves legitimate customer overrides while protecting
  accounting and limit state with the stronger mechanism those paths require.
- Future permission convergence can replace duplicated excludes, chown lists,
  and immutable lists with one declared source of truth.
- The policy aligns the home-directory boundary with ADRs 0016, 0022, 0032,
  0041, and 0043 without changing their individual authority decisions.

### Negative and follow-up work

- This ADR does not remove the existing duplicated lists. A separate code task
  must define the registry shape, classify every managed path, migrate writers,
  and add hermetic coverage for omissions and class-preserving repairs.
- Some paths need a deliberate read channel while others must remain hidden
  from the customer; a future registry must carry that distinction rather than
  collapsing it into one mode.
- Filesystems that do not support immutable flags need an observable failure
  path and an operational decision for enforcement-dependent data. That is a
  follow-up implementation concern, not permission to weaken the class.

## Non-goals

- No blanket `chmod`, `chown`, ACL, or immutable-flag migration is performed
  by this ADR.
- No change is made to customer-owned web roots, application-managed trees, or
  the customer/operator PHP separation.
- No redirect comment is required for binary, serialized, or otherwise
  non-text data.

## References

- GH #783 (policy decision and follow-up scope)
- GH #500, #730, #731, #779, and #781 (related drift and enforcement work)
- ADR 0016: Customer-facing PHP tree separation from operator `/scripts/`
- ADR 0022: guiv-delivered customer files must be self-contained
- ADR 0032: system-managed per-user lighttpd configuration belongs in the
  managed template
- ADR 0041: user web-root managed/customer boundary and recovery convergence
- ADR 0043: provisioning-tree committed modes and deploy-time policy
- `scripts/util/userPermissions.php`
- `scripts/lib/lighttpd/userFileWrite.php`
