# ADR 0288: Deluge upload-cap ownership and stopped writes

Date: 2026-11-15
Category: architecture

## Status
Accepted for the #1263 watchdog correction.

## Context
The Deluge watchdog used a configuration write as its restart predicate.
With no account cap it replaced customer-selected limits with unlimited; with
a cap, Deluge's float saves could differ textually from PMSS's integer writes.
A graceful shutdown could overwrite the new setting with the old live value.
Lifting a PMSS cap must still clear the value PMSS applied without claiming
ownership of unrelated customer settings.

## Options Considered
- Always force unlimited without an account cap: discards customer choices.
- Never write without an account cap: leaves a lifted PMSS cap behind.
- Track the last applied cap, compare numerically, and write after daemon exit.

## Decision
Keep positive account caps exact and authoritative. Compare decoded numeric
values rather than text, using the shared Deluge dual-JSON transformer (ADR 0199).
The watchdog compares without writing, uses its existing stop path, and rechecks
liveness before applying managed settings and the upload limit. A daemon still
exiting defers both publication and launch until a later pass.

Before publishing a positive cap, including a provisioning template cap, record
it in `/etc/seedbox/runtime/delugeUploadThrottle/<account>`, outside the home
(ADR 0213). Reuse integer-setting storage with a 0700 directory and 0600 files.
Without an account cap, reset to unlimited only when the saved limit equals the
recorded PMSS cap. Forget the record after successful removal or when a distinct
customer setting has replaced it. Missing records preserve customer settings;
unreadable or invalid records defer reconciliation. Guarded file readers and
writers continue refusing substituted paths.

## Consequences
Uncapped customer limits survive watchdog passes; integer/float save differences
no longer cause restarts. Active positive caps keep their previous exact-value
policy. Existing unrecorded limits cannot be attributed safely and are preserved
when uncapped; no home-file import or value-based migration is attempted.
A customer choosing the same value as a recorded cap remains indistinguishable
from PMSS's value. Full provisioning's template rewrite and the existing daemon
termination policy are separate from this correction.

`DelugeUploadThrottleTest` covers comparison, cap ownership, read-only detection,
stopped writes, file modes and unsafe paths. Execution stays on testing machines;
devbox verification uses changed-file PHP syntax checks only. No live-host
restart or dry-run execution is performed in the build checkout.

## References
- Refs #1263
- [ADR 0199](0199-deluge-configuration-transformation-boundary.md)
- [ADR 0213](0213-home-directory-files-are-display-only-never-system-settings.md)
