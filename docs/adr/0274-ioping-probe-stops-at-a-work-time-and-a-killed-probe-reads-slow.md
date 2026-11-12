# ADR 0274: The ioping probe stops at a work time, and a killed probe reads slow

Date: 2026-11-12
Category: architecture

## Status
Accepted. Changes the probe in `scripts/lib/runtime/ioping.php` (ADR 0220). It also changes what
`iopingHomeMs` (ADR 0236, ADR 0237) reads during heavy I/O.

## Context
The `/home` latency probe sends 60 O_DIRECT 4 KiB reads 0.1 s apart and is killed at 15 s. A
killed probe returned null, and so did any non-zero exit. A null reading is "not measured", so
the readings went missing exactly when the disk was slowest.

A week of published `iopingHomeMs` samples (to 2026-11-12) shows how often:

| Full I/O PSI at the sample | Share with no reading |
|---|---|
| under 10 | 1.7% |
| 20-35 | 9.6% |
| 45-60 | 24.7% |
| 60 and over | 33.1% |

On a host during its RAID5 initial build, 60 requests needed 12.2 s at an average of 169 ms, with
a maximum of 844 ms. That is close to the cap, so a little more load loses the reading. A
consumer of `iopingHomeMs` that sees only nulls has to fall back to pressure, which reads a
host-side array build inside a virtual machine but not on bare metal (2.2% full PSI on that
host). Readings missing on busy hosts also make latency averages read lower than the disk
really answered.

## Options Considered
- **Raise the 15 s cap.** Rejected. A longer probe holds the device longer on a disk that is
  already slow, and the cap would still be crossed.
- **Return the completed samples only.** Rejected. When nothing answered, there is still no
  reading, and the request still pending is the slowest one.
- **Chosen: two changes.**
  - ioping stops sending at a 12 s work time (`-w 12`) and exits normally, reporting what
    completed.
  - The 15 s timeout stays as the hard backstop. When it kills the probe (exit 124 or 137), a
    request was pending for at least that long. The completed samples are kept and one censored
    sample of 15000 ms is added.

## Decision
- `PMSS_IOPING_PROBE_WORK_TIME` is 12 seconds, passed as `-w`.
- `pmssIopingProbeOutput()` returns the captured output plus a `censored time=15000 ms` line when
  the capture exits 124 or 137.
- `pmssIopingMedianMs()` takes that line as one more sample:
  - with nothing completed, it returns 15000;
  - otherwise the median moves at most one position.
- `pmssIopingAverageMs()` (storage benchmark) still returns null for a killed probe, because no
  summary line is added.
- These still mean "not measured" and still return null: a flock skip (exit 75, a previous probe
  still holds the device), a missing binary and any other failure.

## Consequences
- **A slow disk now reads slow.** A host in a RAID build gets a latency reading every cycle, and
  averages include the busiest moments.
- **Dynamic read caps.** ADR 0236 averages the last three readings. A 15000 ms censored sample
  pushes that average above the 25 ms band, so the read-cap multiplier steps down by one factor
  (1.1) per cycle while it lasts. That is the existing saturated-disk behaviour; before this
  change the missed reading was skipped instead. The pressure reader skips readings above
  60000 ms only, so 15000 ms is used.
- **Consumers that average the published value.** One censored sample adds about 52 ms to a day
  average of 288 samples. A probe gets killed only when a single request is still pending 3 s
  after the work time, so a censored sample means a real stall on the host.
- **The probe never runs longer.** Its total wall time is unchanged (at most 15 s).
