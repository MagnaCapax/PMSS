# ADR 0213: Files under a home directory are for display only; system settings never come from them

Date: 2026-10-30
Category: security

## Status

Accepted. Supersedes, for files inside a home directory, ADR 0046's
"Enforced" class and the sentence in ADR 0121 that lets root place an
Authoritative or Enforced file in an account directory as an input; a root
file written into a home is a display copy. Supersedes the `~/.bonus` reader in
ADR 0212's decision; ADR 0177's bonus-adjusted BFQ weights take the bonus from
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
   stored values on every reward cycle, so a node picks them up within one
   cycle after it updates. A value is never imported from a home file: upload
   caps come back from the backend's own userConfig values, and traffic
   bonuses granted with the operator CLI are re-applied only from the CLI's own
   root log entries. Until a node has the store values, its accounts have their
   plan defaults (no priority bonus); disk bonus is unaffected.

## Consequences

- No file in a home directory can change a system setting.
- The #945 value clamps and the root-owned-marker readers are removed with the
  readers.
- Every root reader of a home file is moved or allowlisted in one change; the
  census lives with the implementation.
- The backend gains one change: re-asserting stored values each reward cycle.
- Priority bonus is absent for an account between its node's update and the
  next reward cycle.

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
