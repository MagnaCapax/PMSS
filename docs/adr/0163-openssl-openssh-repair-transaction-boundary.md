# ADR 0163: OpenSSL and OpenSSH repair transaction boundary

Date: 2026-10-23
Category: architecture

## Status
Accepted

## Context
The Debian 12 PECL-ssh2 compatibility library mixed package-version policy,
listener observation, configuration recovery and two dpkg-direct transaction
implementations. Heal and downgrade repeated allocation, download, package-set
checks, installation and cleanup, with important differences in error handling.

## Options Considered
- Retain both transaction implementations after moving them into separate files.
- Introduce a general package-repair framework with callbacks for each step.
- Share the existing transaction using two fixed policy records and retain the
  three public convergence entrypoints.

## Decision
Keep `opensslSsh2Compat.php` as the compatibility loader and distro/dry-run gate.
Separate package queries/formatting, libssl policy, OpenSSH policy, service
observation/recovery and the shared dpkg-direct transaction into focused modules.
The fixed records carry the two existing package sets, labels and diagnostics;
they add no operator options. There is one transaction implementation in-tree.

Heal still installs runit-helper first, selects only OpenSSH debs for its main
install and tolerates that install's nonzero result. Downgrade still unholds
after allocation and before entering the cleanup guard, installs every downloaded
deb and throws on an install failure. Both retain the three-deb minimum, sorted
package order, conffile-preserving flags, private workspace and cleanup labels.
The unhold exception boundary is preserved rather than silently changed here.

Listener observation returns immediately when it finds a clean executable,
removing the separate `anyClean` state. Libssl restart eligibility follows the
existing upgrade/downgrade predicates instead of another mutable `downgraded`
flag. All prior public function names, constants and signatures remain available.

## Consequences
- One package transaction replaces two implementations; two mutable state flags
  disappear. Modules add responsibility boundaries without adding repair modes.
- Version pins, distro guards, dry-run logs, package holds, configuration backups,
  SSH syntax checks, listener checks and restart ordering retain their contracts.
- Forty-three pre-refactor trace snapshots cover success, skip, missing metadata,
  mixed/unreadable process links, download/package/install failures, tolerated
  prerequisite failures, configuration failures and missing listeners. Fixture
  boundaries record commands and filesystem access without executing them.
- This remains a fleet-wide root updater. The behavior lock is exercised only
  on testing machines; this change is not a live repair or a package-policy audit.

## Security Review
The attack surface includes root package installation and SSH availability.
Upstream records describe pre-authentication root execution races in sshd,
agent-provider execution and peer-controlled certificate parsing in OpenSSL.
These histories make package selection, conffile preservation and restart gates
load-bearing; no upstream version claim substitutes for Debian's patch status.

This refactor reads or stores no account or service credentials, and introduces
no new secret logs, listeners, ports or web endpoints. Existing SSH template
copies retain mode 0644; downloaded packages retain the existing private-temp
helper. Account/service credential separation is outside this module's I/O and
remains untouched. Exposure of package versions or command traces introduces no
login credential; compromise of the updater itself would have root scope.

## References
- [OpenSSH security history](https://www.openssh.org/security.html)
- [OpenSSL vulnerability history](https://openssl-library.org/news/vulnerabilities/index.html)
- [Updater workflow](../update.md)
- [Generated configuration and bounded convergence repair](0036-pmss-owned-config-files-are-generated-from-a-template-never-parsed-and-patched.md)
