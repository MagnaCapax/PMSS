# ADR 0325: The monthly IOPS budget holds a /home read cap on an account over budget

Date: 2026-11-23
Category: architecture

## Status
Proposed

## Context
`iopsLimitEnforcer.php` (hourly) compares an account's I/O operations with the
monthly budget in `/etc/seedbox/runtime/iopsLimits/<user>`. On cgroup v2 it asked
systemd for a 100 IOPS `/home` cap. PMSS hosts run cgroup v1 (ADR 0019), where
systemd applies no IOPS limit, so since #1307 the enforcer only writes the
over-budget marker and logs the overage. A budget that changes nothing is not
enforced.

Three properties of the old design made it weak even where a cap could apply:
the 30-day window trips late on a burst and keeps a heavy account over budget for
a month after it stops; the release came on the first hour back within budget,
so an account could cycle in and out of the cap; and a fixed 100 IOPS cap ignores
the size of the budget.

A lower BFQ weight was considered as the exceed action. It does not work for the
accounts that exceed budgets: storage accounts already run at the lowest weight
class, BFQ is work-conserving, and inside a KVM guest it ranks only that guest's
accounts. Read caps on Linux 6.1 hit the blk-throttle journal stall (ADR 0243);
from 6.12 they are safe, and ADR 0271 already lets the running kernel decide that
through `pmssDynamicIopsKernelReady()`.

## Options Considered
- Lower BFQ weight while over budget: no effect at the weights already in use.
- A new cron or state file for the hold: duplicates the enforcer's marker and the
  single read-cap writer (`cgroupIopsLimitApply.php`).
- Hold a read cap through the existing marker and the existing writer: chosen.

## Decision
- Usage is the last 7 days of `/home` operations (`raw.week`) scaled to 30 days,
  compared with the monthly budget. A burst trips within days; a capped account
  that slows down recovers within days.
- Over budget, the enforcer writes the marker with the enforce time, as before.
- `cgroupIopsLimitApply.php` (every 5 minutes, the only read-cap writer) gives a
  marked account a `/home` read cap of `max(30, budget / 30 days)` IOPS, or the
  lower of that and any other cap it computed. Audit rows carry the reason
  `monthly-iops-budget`. It writes the cap only where
  `pmssDynamicIopsKernelReady()` holds; elsewhere the account is marked and logged
  and gets no cap.
- The account is released only when its usage is within budget and the marker is
  at least 3 days old. A budget set to 0 releases at once. A marker without a
  stored time counts from its mtime.
- The user log states the cap and the earliest release time, or that this kernel
  holds no read cap.
- The floor, the hold and the window are constants in `iopsLimit.php`, not policy
  keys: one fleet value, no per-host override (ADR 0233).
- The floor is a whole ten: blk-throttle delivers whole tens per 100 ms slice
  (`pmssReadLimitDelivered`, ADR 0232), so a floor of 30 is the rate delivered.
- Writes stay as they are: v1 throttles do not bind buffered writes.
- The cap writer records the held cap it applied in the root-only
  `/etc/seedbox/runtime/iopsBudgetCap/<user>`. This record is not a second hold
  state. The marker says the account is held; the record says which kernel value
  the hold wrote. After release, when no other cap replaces it (for example a
  dynamic cycle in its keep-kernel fallback, or an account the dynamic cycle gives
  no cap), the writer clears the `/home` read cap only if the kernel still holds
  the recorded value. It audits this as `monthly-iops-budget-release` and removes
  the record once the cap is gone. Without the record, a released account kept
  its held cap for as long as the fallback lasted (#1307 acceptance UC05).
- `terminateUser` removes `iopsLimits/<user>`, `iopsLimitEnforced/<user>` and
  `iopsBudgetCap/<user>` once the name is unassigned, so a later account with
  the same name does not start held (UC12). The failed-provision cleanup removes
  the same three. `recreateUser` does not terminate, so a rebuild keeps the hold.
- `terminateUser` also moves the account's per-user resource and traffic logs
  (`/var/log/pmss/{resources,traffic,traffic-ingress}/<user>`, and the traffic
  `-localnet` file) into a root-only `terminated/` subdirectory, named with the
  termination time. The history is kept (ADR 0044), but the budget no longer
  judges a later account with the same name on the old account's operations.

## Consequences
- The cap bites only an account reading faster than its cap; an account slightly
  over budget at a low rate sees no change until its usage falls.
- No account gets a cap until a budget value is set for it through provisioning;
  the published terms state the budget before values are set.
- Where the budget cap is the one written, the cap writer publishes it to the panel
  read-limit status after the dynamic cycle, with `reason: monthly-budget`, and the
  panel names the budget instead of disk contention.
- The v2 branch keeps its 100 IOPS clamp; no PMSS host runs v2.
- The cap covers every read the account makes from `/home`, including the
  account's own panel PHP. If a held account keeps reading at its cap, its own
  load competes with its panel: a page whose files are not in the page cache can
  take more than 10 s (measured at a cap of 30 with a random-read load running, UC09).
  That is the hold working, not a fault. The notice the account gets before the
  hold is the primary channel, and the warm panel still shows the budget text.

Tests: `scripts/lib/tests/development/IopsBudgetHoldTest.php`.
