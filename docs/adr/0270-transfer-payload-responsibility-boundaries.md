# ADR 0270: Transfer payload responsibility boundaries

Date: 2026-11-11
Category: architecture

## Status

Accepted.

## Context

The transfer payload library combined scratch layout, SSH transport, Expect
authentication, rsync pass selection, Docker handover and container decoding.
Nine probe builders independently assembled Bash headers and SSH framing.
Subordinate-ID mapping also kept a target index and an offset alongside the
target ranges. These mechanisms obscured payload policy without adding a
distinct execution boundary.

## Options Considered

- Retain the combined builder and repeated framing.
- Introduce payload objects and a command registry with dynamic dispatch.
- Retain the public facade and helpers, separate payload responsibilities,
  share probe framing, and consume target ranges directly (chosen).

## Decision

`remoteScripts.php` retains scratch paths and loads `sshScripts.php`,
`rsyncScripts.php` and `dockerScripts.php`. These own SSH/probe/Expect bytes,
rsync pass policy and Docker handover respectively. The receiver namespace,
home-statistics and repeat-safety modules use the same SSH script framer.
Suffixes and private-output umasks remain explicit caller policies. No public
helper is removed, and no parallel builder remains.

ID mapping consumes the first sorted target range until exhausted, then removes
it from the local queue. It retains sorting, initial overlap checks, primary-ID
protection, insufficient-allocation errors and line order. Input arrays remain
unchanged. The index, offset and separately derived available count disappear.

## Consequences

- Nine framing mechanisms become one; two cursor states disappear. Three
  responsibility modules and two framing parameters replace those duplicated
  sites: the explicit mechanism inventory decreases by five.
- Transfer order, shell quoting, rsync excludes, bandwidth/recovery options,
  namespace gates, container caps, pipeline status checks and return codes
  retain their existing contracts. There is no deployed-state migration.
- Characterization locks complete probe and Docker script bytes, rsync command
  bytes before receiver wrapping, range splitting and refusal messages/codes.
  Existing integration tests retain coverage of the real receiver, private
  scratch files, credential removal and actual generated-shell behavior.
- The home-file allowlist follows the rsync source move with the same marker
  set; the password source contract follows Expect with the same prohibition.
- Only changed-file PHP syntax checks run on the dev box. The lane executes
  characterization and integration tests on the testing machine.

## Security Review

The upstream rsync advisory history includes malicious-peer memory corruption,
path traversal and symlink races; OpenSSH documents client impersonation and
server-side denial-of-service and code-execution defects. This change preserves
the existing transport flags and receiver confinement; it does not establish
the installed binaries' patch status. An exposed transfer password can authorize
SSH shell and account-file access. It remains an operator-supplied transient
Expect environment value, never a generated-script literal or account credential
store, and the account receiver still clears it before launch. No primary/service
credential storage, logging, secret modes, bindings or public endpoints change.

## References

- [Transfer metadata boundaries](0223-transfer-metadata-interpretation-boundaries.md)
- [Account receiver confinement](0092-inode-safe-account-data-movers.md)
- [Transfer namespace mapping](0095-transfer-rsync-in-account-user-namespaces.md)
- [Operational safety](../security/operational-safety.md)
- [Rsync advisories](https://rsync.samba.org/security.html)
- [OpenSSH advisories](https://www.openssh.org/security.html)
