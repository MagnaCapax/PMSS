# ADR 0085: Read-only benchmark of the mounted home device

Date: 2026-10-06
Category: architecture

## Status

Accepted

## Context

Operators need measured sequential read MB/s and random 4k read IOPS for the
array customers use. The existing benchmark starts with file jobs that can
allocate up to 500G on `/home`, so it cannot safely run on occupied servers.
Fresh, unwritten filesystem extents can read back as zeros and do not provide
a valid read measurement. The `--devices` path measures individual disks or VM
virtual disks rather than the mounted array. Debian 12 also lacks fio in its
package selections.

## Options Considered

- Use a tiny `--size` in file mode: still writes to customer storage and reads
  may measure zero-filled extents. Rejected.
- Use per-member `--devices`: measures the wrong device on both VMs and bare
  metal arrays. Rejected.
- Read the block device mounted under `--target`: directly measures the
  customer-visible device without a benchmark file. Selected.

## Decision

Add an opt-in `--home-device` mode. Resolve the device reported by `df -P` for
`--target` (default `/home`), reject unsafe or unreadable non-block paths, and
never substitute another device. Record device size, rotational flag, and md
array state when applicable. Run the existing idle preflight and honor
`--require-idle`. With fio, run read-only random 4k and sequential 1M jobs on
the device. Without fio, record random read as unmeasured and use the existing
read-only dd sequential job with a random offset. Skip file and per-member jobs;
`--size` has no effect. Add fio to the Debian 12 selections.

## Consequences

- Operators can measure the mounted array on occupied servers without writing
  a benchmark file or opening the device for writes.
- Benchmarks still consume read I/O; the idle gate remains available to avoid
  disrupting busy hosts.
- The dd fallback provides sequential throughput only. Its result must not be
  interpreted as random IOPS.
- Existing invocation modes and the post-install trigger retain their behavior.

## References

- MagnaCapax/PMSS#1015
- `scripts/lib/storageBenchmarkHomeDevice.php`
