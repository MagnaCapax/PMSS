# ADR 0318: GUI watchdog restores damaged panel files through the root stage

Date: 2026-11-21
Category: architecture

## Status

Accepted

## Context

#1162: ADR 0207 lets the nightly skeleton copy deliver managed files when the
account cannot allocate a replacement. The GUI watchdog still has only the
account writer, so a damaged core panel file remains damaged between updates
when quota grace has expired or `/home` is full. Its cron output is discarded,
including the account writer's failure cause.

The restore trigger is any SHA-1 difference. The local skeleton can trail the
served file list, so an ungated root publish could overwrite valid newer files.

## Decision

Extract the existing post-account-write fallback into
`pmssUpdateUserRootStageFallback()` in `update/users/rootStageDelivery.php`.
`copyToUserSpace()` retains its behavior and log lines; checkGui restore becomes
the second caller of this shared helper. The account writer stays first.

Only after that writer fails, use the named, read-only
`pmssCheckGuiRestoreTargetIsDamaged()` gate. It accepts a missing file, an empty
single regular file, bytes that throw `ParseError`, or a strict shorter byte-prefix
of the skeleton copy. The prefix check also catches truncation inside inline HTML
that still parses. Read through `pmssReadRegularFileContentsVerified()` with a
cap equal to the skeleton size. Fail closed on null reads (including unreadable,
linked, non-single-regular or over-cap copies), unavailable tokenizer support, or
any other tokenizer `Throwable`. Never log file content. A complete newer copy
that parses and is not a shorter prefix is left alone on this fallback path.

Reuse ADR 0207 eligibility and the unchanged literal root:<account primary group>
0755 publication. A failed publish keeps the old file, with no retry and no chown
by path. Leave the existing ownership check unchanged for account-written files;
a successful root-stage restore returns before it.

checkGui does not run the permission pass. Hand-back happens at the next update
or permission pass; meanwhile the file is root:<group> 0755, so the on-load
updater's `is_writable()` is false and it does not rewrite it.

The covered files are exactly those restored by the existing checkGui loop:
`toggleAppCatalog.php`, `index.php`, `scriptsInc.php`, and the twelve panel helper
parts. `welcomeMessage.php` and `pikkumiesPanel.php`, loaded directly by
`index.php`, remain outside that loop: this is a recorded limit.

The trigger runs every 10 minutes, bounded by 15 core files of about 169 KB total
skeleton bytes (169,374 bytes in this checkout), well below the 256 MiB reserve
floor that each delivery must preserve. PMSS writes only user quotas, so a group
grace clock cannot start on hosts it provisioned; hand-set group limits remain
a residual case.

Correct ADR 0207's statement that the on-load updater skips these accounts
anyway: this holds for accounts over their own limit only. The served gate uses
quota arithmetic with no free-space test, so it runs on full `/home` for accounts
under their limit. The root-owned file's write check prevents it from rewriting
a watchdog delivery before hand-back.

Append cron output to `/var/log/pmss/checkGui.log` so the account writer failure
cause is kept beside the Logger messages. The existing `check*.log` rotation
already covers it. Keep the damage gate named so #1231 can reuse it when narrowing
the account-write trigger without conflicting with this restore path.

## Options Considered

- Ungated fallback on any divergence: rejected because the skeleton can trail
  valid newer served files.
- Changing the ownership check: rejected; retain its account-path contract and
  return separately after a root-stage delivery.
- Adding the two `index.php` includes to the restored list: deferred; keep the
  existing file inventory and record its limit.
- Registry design: declined by ADR 0207; reuse its existing delivery mechanism.

## Consequences

Damaged core panel files can be restored between nightly updates even when the
account cannot write. Eligibility, reserve accounting and publication stay
shared with skeleton delivery; failures retain the old bytes and their cause.
The account-write trigger and managed file list remain unchanged.

A truncation of a newer served version on a host whose skeleton is behind is
not detected by the prefix check if it still parses. Copies larger than the
skeleton are not examined, even if they would not parse. These residuals keep
the fallback bounded and avoid replacing valid newer content.

## References

- #1162 (remaining GUI watchdog restore path)
- #1231 (narrowing the account-write trigger)
- ADR 0207, ADR 0121, ADR 0024, ADR 0099, ADR 0291
- `scripts/lib/update/users/rootStageDelivery.php`
- `scripts/lib/gui/watchdogRepair.php`, `scripts/cron/checkGui.php`
- `etc/seedbox/config/root.cron`, `docs/contracts.md`
