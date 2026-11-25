# ADR 0344: Converge account homes with sticky protection

Date: 2026-11-25
Category: security
Status: Proposed

## Context
Issue #1322 reports fresh OpenClaw installs rejecting the group-writable account
home as an unsafe temporary-workspace ancestor. The account permission policy
sets the home to `0770`, so convergence also clears a manually added sticky bit.
The browser configuration step invokes the package's config writer and refuses
to continue when that writer fails. No package validation should be weakened.

## Options Considered
- Add sticky protection to the existing home mode: retains owner/group access.
- Remove group write: changes existing group access relied on by older users.
- Relocate OpenClaw state: introduces a new persistent layout and migration.

## Decision
Set the home entry in `permissionsPolicy.php` to PHP octal `01770`. The existing
non-recursive, account-side mode applier restores that mode on each convergence.
Use the same policy for all accounts, without application markers or extra
chmod paths. Keep child-directory modes and every path/owner/link guard intact.

## Consequences
The home owner can still create, remove and rename entries, including entries
owned by root. Other group members retain read/write/search access but can no
longer remove or rename entries owned by someone else. World access stays denied.
This is an added restriction, not a substitute for private application state.
Existing homes receive it through their next normal permission convergence.
No OpenClaw state is moved and no automatic reinstall is added.

The policy snapshot records the deliberate mode change. A private-fixture test
uses the real policy and applier for five starting modes, repeats convergence,
and checks that private child modes are untouched. Only changed-file PHP syntax
is checked on the devbox; fixture execution belongs on the testing machine/CI.
Before rollout, verify a fresh OpenClaw install and start after a repeated
permission pass on the testing machine. No live install or dry run was performed.

## Security Review
Reviewed OpenClaw's CVE-2026-26322 gateway URL/SSRF advisory and
GHSA-v3j7-34xh-6g3w local gateway-token disclosure advisory. They describe
network-target and local-listener risks, not this permission mismatch. A stolen
gateway token can grant the account's agent capabilities and thus its files and
command execution (ADR 0087). This change does not claim to fix those advisories.
The coreutils CVE-2017-18018 ownership race also informs the review: existing
account-side chmod and non-dereferencing ownership guards remain in place.

Primary and service credentials remain separate; no credential is read, written
or logged by this change. Secret-file modes, ownership and redaction are unchanged.
SSH, panel authentication, API authorization, listener binds and public exposure
are unchanged. Sticky protection restricts deletion by other group members; it
does not block the home owner or authorize traversal into another account.

## References
- Refs #1322
- [Account convergence boundaries](0281-account-permission-convergence-boundaries.md)
- [OpenClaw installer](0087-separate-per-user-openclaw-installer.md)
- [GNU mode semantics](https://www.gnu.org/software/coreutils/manual/html_node/Mode-Structure.html)
- [Gateway URL advisory / CVE-2026-26322](https://github.com/openclaw/openclaw/security/advisories/GHSA-g6q9-8fvw-f7rf)
- [Local token disclosure advisory](https://github.com/openclaw/openclaw/security/advisories/GHSA-v3j7-34xh-6g3w)
- [Coreutils ownership race / CVE-2017-18018](https://security-tracker.debian.org/tracker/CVE-2017-18018)
