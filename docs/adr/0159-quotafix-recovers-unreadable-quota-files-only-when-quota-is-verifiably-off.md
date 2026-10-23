# ADR 0159: quotaFix recovers unreadable quota files only when quota is verifiably off

Date: 2026-10-23
Category: data

## Status
Accepted

## Context
`quotaFix.php` skips `quotacheck` when `quotaoff -av` fails, because a recount while quota is
on can damage the quota file. quota-tools turn quota on or off only after the quota file passes
a header check, so a zero-filled or truncated `/home/aquota.user` or `/home/aquota.group` (left,
for example, by an interrupted `quotacheck`) makes both `quotaoff` and `quotaon` fail with
"Cannot find quota file". The kernel then has quota off, quotaFix skips the recount, `quotaon`
fails again, and enforcement stays off on every run. `quotacheck` without `-c` also refuses
such a file ("corrupted headers"), so only a fresh file (`-c`) repairs it.

## Options Considered
- Keep the skip and leave the state to manual repair. The canonical repair tool can never fix it.
- Run `quotacheck -c` on every filesystem whenever `quotaoff` fails. A failure while quota is still
  on, or with a readable file, would recount a live file or discard readable limits.
- Recover `/home` only when `quotaon -p /home` proves every declared type is off, per type, and
  create a fresh file only for a type whose file has a header `quotacheck` rejects.

## Decision
`pmssQuotaFixCheckCommands()` chooses quotaFix's `quotacheck` commands. After a successful
`quotaoff` it returns the unchanged `quotacheck -avugmn`. After a failed `quotaoff` it returns a
per-type `/home` recount only when `quotaon -p /home` exits 0 and prints "<type> quota on /home
(...) is off" for every type fstab declares; any other answer, an unreadable fstab or no declared
type returns no command and quotaFix skips the recount as before (fail closed). In that recount a
type whose quota file exists with a wrong magic, an unknown version or a short header gets `-c`
and `-b` (quotacheck keeps the old file as `aquota.<type>~`); a readable or missing file is
recounted without `-c`, so readable limits are kept. quotaFix never removes or moves quota files
itself.

## Consequences
- A host whose quota files were zeroed recovers on the next quotaFix run: fresh files, quota on.
- Limits held in an unreadable file are not recovered; they were not readable by any quota tool.
  The `~` backup stays for manual inspection and `pmssWarnUnexpectedQuotaFiles` reports it until
  an operator removes it.
- The failed `quotaoff` still sets exit code 1, so a recovered run writes no completion stamp
  (ADR 0140) and the next update runs the normal cycle once more.
- A localized `quotaon -p` output does not match and keeps the skip.

## References
- `scripts/util/quotaFix.php`, `scripts/lib/update/services/quota.php` (`pmssQuotaFixCheckCommands`, `pmssQuotaFileHeaderInvalid`).
- ADR 0140: quota recalculation only when enforcement is off.
- quota-tools `quotacheck.c`, `quotacheck_v2.c` (header check, `-c`, `-b`), `quotaon.c` (`newstate`, `print_state`).
