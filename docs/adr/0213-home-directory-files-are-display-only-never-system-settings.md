# ADR 0213: Files under a home directory are for display only; system settings never come from them

Date: 2026-10-30
Category: security

## Status

Accepted. Supersedes, for files inside a home directory, ADR 0046's
"Enforced" class and the sentence in ADR 0121 that lets root place an
Authoritative or Enforced file in an account directory as an input; a root
file written into a home is a display copy. Supersedes the `~/.bonus` reader in
ADR 0218's decision; ADR 0177's bonus-adjusted BFQ weights take the bonus from
the store instead of the home file. Extends ADR 0152, which already made billing
identity come only from the provisioning store with home markers kept for
display.

## Context

The original PMSS (import 78d08ba3, 2023) read one bonus file from a home
directory, `~/.bonusQuota`, and only in the customer panel (`welcome.php`).
Limits came from values the provisioning backend sent or root wrote outside the
home: the disk bonus was already inside the quota the backend set with
`setquota`, and the traffic limit was the root-only file
`/etc/seedbox/runtime/trafficLimits/<user>` (mode 0600). The 2023 traffic cron
did read the account's usage counter from the home (`~/.trafficData`) until it
moved to a root runtime copy. The backend still sets the final disk quota and
writes `.bonusQuota` as a display figure.

In 2026 root jobs started reading home bonus markers as inputs: `~/.bonusTraffic`
for the monthly traffic allowance (34b341eb, 2026-02-21), `~/.bonus` for disk
I/O weight (62b5dfa6, 2026-05-29) and for CPU weight (3a6fde94, 2026-10-30).
Other root jobs read other home files as well.

Everything under `/home/<account>` is account-controlled (ADR 0121). A value
clamp (#945) only bounds how much an account can grant itself. An ownership
check on such a file is not a substitute: the path is still account-controlled
(ADR 0121).

A root-owned per-user store already exists: `/etc/seedbox/config/users/<user>.json`
(`UserConfigStore`, written `root:root 0640`). `cron/cgroupIopsLimitApply.php`
already reads per-user limits from it, and `util/writeHomeMarker.php` already
records billing identity there before it writes the home copy (ADR 0152).

## Decision

1. **A file under `/home/<account>` is never a system setting.** A setting or
   marker file in a home directory exists only for display in the account's
   panel and the account's own tools. No system process (root cron, root
   utility, provisioning script, service watchdog, config generator, report
   sent to the backend) reads such a file to decide or report
   anything the system grants, limits, routes or reports: quota, traffic
   allowance or usage, throttle, CPU or I/O weight, priority, shared-service
   configuration, alert settings, billing state, or any future limit.
2. **System settings live only in root stores outside `/home`:** the per-user
   store `/etc/seedbox/config/users/<user>.json` and the existing
   `/etc/seedbox/runtime/` files. A missing or invalid value means the plan
   default, never a value taken from a home file.
3. **The writer is the provisioning backend, through the root CLIs.** Every
   CLI that sets a per-user value (`writeHomeMarker.php` for every value
   marker it accepts, `userBonusTraffic.php`, `userTrafficLimit.php`,
   `userConfig.php` including its store-only mode) writes the root store first.
   Only then may it write a home copy for display. If the store write fails,
   the CLI exits non-zero with a logged line and writes no display copy. Store
   read-modify-write runs under a per-user lock.
4. **The account's own applications and switches.** Files the account edits
   for its own applications (client configs, the `*.custom` overrides, lighttpd
   drop-ins: ADR 0046 "customer-editable") are read by those applications
   running as the account. A root job may honour an account's own on/off or
   preference flag only when the worst a forged value can do is switch off, or
   choose among, the account's own services within its plan. A port the
   account prefers is a request the root port manager validates and assigns.
   No home file may raise a limit, priority, cap or allowance, or change a
   shared service.
5. **Bonus values:** `bonusPercent` (integer, 0 to the 300% policy maximum) for
   disk I/O and CPU weight, and `bonusTrafficGiB` (integer GiB) added to the
   root traffic limit, both in the per-user store, next to the other per-user
   limits root crons already read there. Disk is unchanged: the backend's
   `setquota` value is the limit and `.bonusQuota` stays display only.
6. **Enforcement:** `homeMarkerRegistry.php` classes every marker "display". A
   contract test fails when root-run code parses a value from a file under a
   home path and uses it as a setting, except in an explicit allowlist of files
   that may do so under Decision 4 (account switches) or that operate on the
   home itself (permissions, transfer, termination).
7. **Rollout:** the reader change ships through the PMSS security-fix release
   process, not the rolling update. The backend re-asserts each account's
   bonus percent once a day: the reward cycle sends `writeHomeMarker.php .bonus`
   for every account of a node in that node's daily slot, so a node has the
   store value within a day after it updates. Only a re-assert that reaches a
   node after its update counts, because the earlier writer records only the
   home copy. The backend holds no upload caps and no bonus traffic: an upload
   cap exists only where `userConfig.php --upload-throttle-kib` was run on the
   node, and bonus traffic only where `userBonusTraffic.php` was. A value is
   never taken from a home file. A one-time import on update was considered
   for both, from root-only evidence: an upload cap from a regular, root-owned,
   not group- or world-writable `~/.torrentThrottle`, and bonus traffic from
   the last `bonus traffic set to N GiB` line of the root-only per-user log,
   only while the home copy still holds the same N, so the home file could stop
   an import but never create or raise one. A read-only count across the fleet
   on 2026-10-30 (173 of 174 nodes) found no such upload cap and no such log
   line, so no import ships. A value set by hand on a node is set again with
   the same CLI after the update. Until a node has the store values, its
   accounts have their plan defaults (no priority bonus); disk bonus is
   unaffected.

## Consequences

- No file in a home directory can change a system setting.
- The #945 value clamps and the root-owned-marker readers are removed with the
  readers.
- Every root reader of a home file is moved or allowlisted in one change; the
  census lives with the implementation.
- The backend gains one change: re-asserting the bonus percent once a day.
- Priority bonus is absent for an account between its node's update and that
  node's next daily re-assert, normally within a day.
- Upload caps and bonus traffic have no backend source. They live in the node's
  store only, set by the root CLIs; nothing re-sends them.
- `userTransfer.php` does not carry the source node's store: the pull runs as
  the account and cannot read it, and the account's files are not a source.
  The target store holds what provisioning sends for the new account; a value
  set by hand on the source node is set again on the target.
- Customer email addresses are not stored on nodes. `writeHomeMarker.php`
  accepts integer markers only, and usage alerts on a node report
  `recipient_missing` unless an address is in the store.

## Alternatives considered

- **Keep reading the home file, clamp the value** (#945): the account still
  sets its own bonus up to the clamp. Rejected.
- **Keep reading the home file, trust only a root-owned file**: still a read of
  an account-controlled path (ADR 0121). Rejected.
- **Send the values on the backend's `userConfig.php` line**: `userConfig.php`
  has a store-only mode that does not restart rTorrent, so this works too.
  Recording the store in the CLIs the backend already calls needs no change to
  the ongoing backend path, so it is the simpler route; the store keys stay the
  same either way.
- **Drop the priority bonus and keep disk only, as in 2023**: a product
  decision, not a security one; out of scope here.
