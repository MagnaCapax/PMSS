# ADR 0213: Files under a home directory are for display only; system settings never come from them

Date: 2026-10-30
Category: security

## Status

Accepted. Supersedes the "enforced" class that ADR 0046 gives home markers
such as `.bonus` and `.bonusTraffic`, and the `~/.bonus` reader in ADR 0212's
decision.

## Context

The original PMSS (import 78d08ba3, 2023) read one bonus file from a home
directory, `~/.bonusQuota`, and only in the customer panel (`welcome.php`).
Limits came from values the provisioning backend sent or root wrote outside the
home: the disk bonus was already inside the quota the backend set with
`setquota`, and the traffic limit was the root-only file
`/etc/seedbox/runtime/trafficLimits/<user>` (mode 0600). The backend still
works this way for disk: it sets the final quota, then writes `.bonusQuota` as
a display figure.

In 2026 root jobs started reading home files as inputs to system settings:

| Since | Root job | Home file | Decides |
| --- | --- | --- | --- |
| 2026-02-21 (34b341eb) | `cron/trafficLimits.php` | `~/.bonusTraffic` | monthly traffic allowance |
| 2026-02-24 (ad4acbaa) | rTorrent watchdog, `lib/user/deluge.php`, `lib/user/qbittorrentThrottle.php`, `util/userConfig.php` | `~/.torrentThrottle` | upload cap written into the client config |
| 2026-05-29 (62b5dfa6) | `cron/cgroupBfqWeightApply.php` | `~/.bonus` | disk I/O weight |
| 2026-08-26 (5b83f6f9) | `cron/usageAlertsNotify.php` | `~/.bonusTraffic` | traffic alert threshold |
| 2026-10-30 (3a6fde94) | `cron/cgroupBonusCpuWeightApply.php` | `~/.bonus` | CPU weight |

`lib/user/resourcesListModel.php` also reports `~/.trafficLimit` to the backend
through `userResourcesList.php --jsonl`.

Everything under `/home/<account>` is account-controlled (ADR 0121). The
account can create or replace these files, and `util/userPermissions.php`
returns home files to the account on boot and on update, so a root-owned copy
does not stay root-owned. A value clamp (#945) only bounds how much an account
can grant itself; it does not remove the trust. An ownership check rejects a
replaced file but still reads a path the account controls, and gives no value
once the ownership sweep has run.

A root-owned per-user store already exists: `/etc/seedbox/config/users/<user>.json`
(`UserConfigStore`, written `root:root 0640`). `cron/cgroupIopsLimitApply.php`
already reads per-user limits from it, and `util/writeHomeMarker.php` already
records billing identity there before it writes the home copy.

## Decision

1. **A file under `/home/<account>` is never a system setting.** A setting or
   marker file in a home directory exists only for display in the account's
   panel and the account's own tools. No system process (root cron, root
   utility, provisioning script, service watchdog, report sent to the backend)
   reads such a file to decide anything the system grants, limits or reports:
   quota, traffic allowance, throttle, CPU or I/O weight, priority, port bound
   by a shared service, billing state, or any future limit.
2. **System settings live only in root stores outside `/home`:** the per-user
   store `/etc/seedbox/config/users/<user>.json` and the existing
   `/etc/seedbox/runtime/` files. A missing or invalid value means the plan
   default, never a value taken from a home file.
3. **The writer is the provisioning backend, through the root CLIs.** A CLI
   that sets a per-user value (`writeHomeMarker.php`, `userBonusTraffic.php`,
   `userTrafficLimit.php`, `userConfig.php`) writes the root store first. Only
   then may it write a home copy for display. If the store write fails, the CLI
   fails and writes no display copy.
4. **The account's own configuration stays the account's.** Files the account
   edits for its own applications (client configs, the `*.custom` overrides,
   lighttpd drop-ins: ADR 0046 "customer-editable") are read by those
   applications running as the account. They are not system settings and root
   does not act on their content.
5. **Bonus values:** `bonusPercent` (integer, 0 to the 300% policy maximum) for
   disk I/O and CPU weight, and `bonusTrafficGiB` (integer GiB) added to the
   root traffic limit, both in the per-user store. Disk is unchanged: the
   backend's `setquota` value is the limit and `.bonusQuota` stays display only.
6. **Enforcement:** `homeMarkerRegistry.php` classes every marker "display". A
   contract test fails when root-run code under `scripts/cron`, `scripts/util`
   or `scripts/lib` reads a file under a home path for anything other than the
   account's panel or customer-facing stats.
7. **Rollout:** ship PMSS first, then have the backend re-push each account's
   current values from its own records. A value is never imported from a home
   file. Until the re-push reaches an account, that account has its plan
   defaults (no priority bonus); its disk bonus is unaffected.

## Consequences

- An account can no longer raise its own disk or CPU priority, traffic
  allowance or upload cap by writing a file.
- The #945 value clamps and the root-owned-marker reader are removed with the
  readers.
- The ownership sweep in `userPermissions.php` no longer matters for system
  settings, because none are read from a home file.
- Existing readers move to the root store in one sweep: the five listed in
  Context plus the `~/.trafficLimit` report. `.torrentThrottle` gets a store
  key; its writer is root.
- Values that exist today only in home files (operator-CLI traffic bonuses,
  upload caps) are listed with a read-only fleet check and re-applied through
  the CLIs, so they land in the store; nobody's grant is taken from the file.
- Priority bonus is absent for an account between the PMSS update and the
  backend re-push.

## Alternatives considered

- **Keep reading the home file, clamp the value** (#945): the account still
  sets its own bonus up to the clamp. Rejected.
- **Keep reading the home file, trust only a root-owned file**: still a read of
  an account-controlled path (ADR 0121), and the ownership sweep turns every
  marker back to the account. Rejected.
- **Send final weights on the `userConfig.php` line from the backend**: matches
  the store design, but `userConfig.php` restarts rTorrent (ADR 0212) and
  bonuses change often. Possible later if a no-restart path exists; the store
  keys above stay the same.
- **Drop the priority bonus and keep disk only, as in 2023**: a product
  decision, not a security one; out of scope here.
