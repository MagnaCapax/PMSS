# ADR 0274: The ioping probe reuses its file, waits up to 60 s, and a killed probe reads slow

Date: 2026-11-12
Category: architecture

## Status
Accepted. Changes the probe in `scripts/lib/runtime/ioping.php` (ADR 0220). It also changes what
`iopingHomeMs` (ADR 0236, ADR 0237) reads during heavy I/O.

## Context
The `/home` latency probe sends 60 O_DIRECT 4 KiB reads 0.1 s apart and was killed at 15 s. A
killed probe returned null, and so did any non-zero exit. A null reading means "not measured", so
the readings went missing exactly when the disk was slowest.

A week of published `iopingHomeMs` samples (to 2026-11-12) shows how often:

| Full I/O PSI at the sample | Share with no reading |
|---|---|
| under 10 | 1.7% |
| 20-35 | 9.6% |
| 45-60 | 24.7% |
| 60 and over | 33.1% |

Measured on a bare-metal host during its RAID5 initial build:

| Run | Wall time | Note |
|---|---|---|
| One request, directory target | 47-53 s | the request itself took 21-37 ms |
| 60 requests, directory target | 67 s | |
| One request, reusing the working file (`-k`) | 0.5-0.6 s | after a first run of 35 s that created the file |
| 60 requests, reusing the working file | 9.5 s | average 134 ms |

For a directory target, ioping creates, writes and syncs a 1 MiB temporary file before its first
read and removes it afterwards. On a busy array that write, not the read, is what crossed the
15 s cap. Every probe then failed before it measured anything.

A consumer of `iopingHomeMs` that sees only nulls has to fall back to pressure. Pressure reads a
host-side array build inside a virtual machine but not on bare metal (full PSI 2-12% on that
host during the build). Readings missing on busy hosts also make latency averages read lower than
the disk really answered.

## Options Considered
- **Raise only the cap.** Rejected alone. Every run would still pay the file setup.
- **Return the completed samples only.** Rejected alone. When nothing answered there is still no
  reading, and the request still pending is the slowest one.
- **Chosen: four changes.**
  - The periodic host probes reuse ioping's working file (`-k`, `ioping.tmp` in the target
    directory, 1 MiB, root-owned).
  - ioping stops sending at a 12 s work time (`-w 12`) and exits normally, reporting what
    completed.
  - The hard timeout is 60 s, so a request that stalls for tens of seconds is still measured
    instead of cut off. This catches failure modes the 15 s cap hid.
  - When the timeout still kills the probe (exit 124 or 137), a request was pending for at
    least that long. The completed samples are kept and one censored sample of 60000 ms is
    added.

## Decision
- `PMSS_IOPING_PROBE_TIMEOUT` is 60 and `PMSS_IOPING_PROBE_WORK_TIME` is 12 seconds (`-w`).
- `pmssIopingProbeOutput($target, $keep)` and `pmssIopingMedianMs($target, $keep)` take an
  optional `$keep` (default false) that adds `-k`.
  - `pmssSystemStatsIopingMs()` passes true. That covers the host statistics and
    `iopingHomeMs`.
  - The storage benchmark (`pmssIopingAverageMs`) keeps creating and removing its own file.
- On exit 124 or 137, `pmssIopingProbeOutput()` returns:
  - the completed request samples;
  - a `censored time=60000 ms` line;
  - no summary line.
- `pmssIopingMedianMs()` takes the censored line as one more sample. With nothing completed it
  returns 60000. Otherwise the median moves at most one position.
- `pmssIopingAverageMs()` stays null for a killed probe.
- These still mean "not measured" and still return null:
  - a flock skip (exit 75: a previous probe still holds the device);
  - a missing binary;
  - any other failure.

## Consequences
- **A slow disk now reads slow, and setup no longer fails the probe.**
  - A host in a RAID build gets a latency reading every cycle.
  - The reading is of reads, as intended, not of temporary-file creation.
  - Averages include the busiest moments.
- **`/home/ioping.tmp` and `/ioping.tmp` stay on every host.** Each is 1 MiB, mode 0600, owned by
  root. Account enumeration reads `/etc/passwd`, so the file is not taken for an account.
- **Dynamic read caps.** ADR 0236 averages the last three readings, and its pressure reader
  skips only readings above 60000 ms, so a censored 60000 ms counts. Such a reading pushes the
  average above the 25 ms band, and the read-cap multiplier steps down by one factor (1.1) per
  cycle while it lasts. That is the existing saturated-disk behaviour. Before this change the
  missed reading was skipped instead.
- **Consumers that average the published value.** One censored sample adds about 208 ms to a day
  average of 288 samples. A probe is killed only when a single request is still pending 48 s
  after the work time, so a censored sample means a real stall.
- **Probes can now run longer, and overlap more.**
  - The worst-case probe now runs 60 s instead of 15 s. It runs that long only on a stalled
    disk.
  - `diskIostat.php` (every 5 min) and `systemStatsLog.php` (offset by 3 min) share the per-path
    flock. An overlap skips one of them for that tick, as before.
