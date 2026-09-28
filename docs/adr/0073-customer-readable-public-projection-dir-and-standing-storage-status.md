# ADR 0073: Public projection directory and standing storage status

Date: 2026-09-28
Category: architecture
Status: Accepted

## Context

The root cron wrote a 0644 host-pressure snapshot beneath `/var/log/pmss`, but
that directory is swept to 0700 root:root. Customer PHP could not traverse it.
The info tab had no standing shared-storage status, and the alert-only notice
was therefore silent fleet-wide. Live `/proc/pressure/io` is customer-readable.

## Options considered

- Open traversal on `/var/log/pmss`: exposes names of other log artifacts.
- Keep the snapshot under the private log tree: leaves the notice broken.
- Use a dedicated public projection directory and read live PSI: preserves the
  private boundary and lets the panel report normal and unavailable states.

## Decision

Root cron publishes only host-pressure ioping data atomically at mode 0644 to
`/var/lib/pmss/public/host-pressure.json`. The directory is a real root:root
directory at mode 0755; symlinks, other file types, and foreign ownership are
refused. Customer PHP reads live five-minute full I/O PSI and uses only fresh
ioping from the snapshot. Info shows the RAID notice then one standing status
row; welcome keeps its RAID-first alert-only notice. See ADR 0016 for the
customer/operator PHP boundary.

## Consequences

The welcome.php host-pressure alert renders for the first time fleet-wide when
pressure is heavy. An unavailable PSI file is visible as unavailable on info;
old kernels without the file omit the row. The old log artifact is left alone.
Per-user throttle markers from #910 symptom 2 remain private: placing them in a
listable public directory would expose other tenants' state. No 0700 directory
mode changes, new cron entries, or customer-side operator includes are needed.
