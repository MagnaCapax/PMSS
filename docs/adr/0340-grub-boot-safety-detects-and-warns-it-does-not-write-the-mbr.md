# ADR 0340: GRUB boot safety detects and warns; update.php does not write the MBR

Date: 2026-11-23
Category: architecture

## Status
Accepted

## Context
A Debian `grub-pc` upgrade replaces the modules under `/boot/grub` and relies on its own `grub-install` to
rewrite `core.img` in the MBR. When that `grub-install` fails, the host keeps running on the old `core.img`
and hangs at the GRUB banner on its next reboot, possibly weeks later, on hardware with no remote console.
`grub-install` appears nowhere in the repository, and the only boot-readiness checks run inside the
dist-upgrade path, so a routine update says nothing about this state.

Repairing it means writing a bootloader to the boot disks of customer hosts without a person present. That
needs a boot-member disk enumerator (the RAID parser yields md member partitions, not physical boot disks),
idempotency coverage on real hardware, and still would not decide which disk the BIOS boots first.

## Options Considered
- Run `grub-install` on every RAID boot member from update.php: a wrongly discovered disk set can brick a
  healthy host, and it cannot be proven in the hermetic suite. Rejected for now.
- Detect and warn only: read-only, cannot change boot state, testable without hardware. Chosen.
- Do nothing: the latent state stays invisible until the reboot that bricks the host.

## Decision
`pmssBootGrubStaleCoreImgCheck()` runs at the end of the boot-settings steps of `update-step2.php` as a
soft-fail step. It reads the kernel boot time from `/proc/stat` and the `upgrade grub-pc` and
`upgrade grub-pc-bin` rows of `/var/log/dpkg.log.1` and `/var/log/dpkg.log`. When any such upgrade is not
older than the boot, it logs one `[WARN]` line naming the upgrade count, the last upgrade time and the boot
time, and telling the operator to run `grub-install` on every boot disk and `update-grub` before the next
reboot. A host that has rebooted since the upgrade has already loaded the new modules with its `core.img`
and is not flagged. An unreadable boot time logs a `[SKIP]` line. A dpkg log that exists but is unreadable or
larger than 16 MiB is skipped and reported in a `[WARN]` line, so a quiet result is never silently partial.

The step never invokes `grub-install`, `update-grub`, debconf or any other writer. `grub-install` and the
MBR stay out of update.php until a separate decision supplies disk discovery, hardware test coverage and
operator sign-off.

## Consequences
- Positive: a latent no-boot state becomes a visible log line on the next update, before the reboot.
- Negative: dpkg logs rotate, so an upgrade older than the previous log is missed on a host that has been up
  that long; dpkg stamps are local time, read in PHP's zone (UTC on the fleet), so a zone mismatch can move
  a boundary case by the offset. The debconf `install_devices_failed_upgrade` flag is not used: it is sticky
  and fires on hosts that rebooted fine.
- Follow-ups: the repair half (all-boot-disk `grub-install`, and `grub-pc/install_devices` covering every RAID
  member) stays open under issue #691 and needs its own decision.

## References
- Issue #691; ADR 0014 (step classification); `scripts/lib/update/distUpgrade/boot.php` (dist-upgrade
  readiness checks, detect-only).
