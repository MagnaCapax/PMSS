# ADR 0283: userTransfer preserves sparse files

Date: 2026-11-14
Category: data

## Status
Accepted.

## Context
userTransfer pulls an account home with `rsync -aHAX`. Without `--sparse`,
rsync writes every hole of a sparse file on the source as real zero blocks on
the target. Torrent clients create sparse files for incomplete downloads, so a
home's allocated size can sit well below its apparent size. A home that fits
its disk quota on the source can then exceed the same quota on the target: the
copy stops with `Disk quota exceeded`, files that never left the source stay
there, and the move reports a size ratio that looks complete.

## Options Considered
- Keep the flags and raise target quotas by the inflation: gives away disk the
  plan does not include, and the inflated blocks still hold nothing.
- Predict the overflow only: warns, but the data still does not fit.
- Add `--sparse` to the transfer: holes stay holes, so the target uses about
  what the source used. rsync rejects `--sparse` only together with
  `--inplace` before 3.1.3; userTransfer does not use `--inplace`.

## Decision
The userTransfer rsync command carries `--sparse` for every pass (main, final
and `--into` recovery). Nothing else in the command changes.

## Consequences
A transferred home's allocated size matches the source's within filesystem
block rounding, so quota checks made against the source's allocated size hold
on the target. Runs of zeros in files that were dense on the source, such as
files a client preallocated, also become holes on the target, so the target
can report less usage than the source; a later write into such a range
allocates normally. Detecting zero runs costs some CPU on the receiver.
Homes already copied without the flag keep their dense blocks; reclaiming that
space is a separate repair (`fallocate --dig-holes`, run as the account).
