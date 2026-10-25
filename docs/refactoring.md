# Refactoring Guidelines

PMSS follows Linux kernel style expectations when restructuring code. Keep the
following points in mind whenever you touch large files:

- **Keep single source files short.** Target ~150 lines per file; if you are
  pushing past ~200 lines, extract cohesive helpers or move logic into
  `scripts/lib/…` so callers compose small units. Splitting early keeps review
  surface manageable and mirrors the Linux kernel guidance captured in the repo
  root `README.md`.
- **Prefer focused modules.** When breaking a script apart, group related
  routines (e.g. package helpers vs. orchestration code) into dedicated files
  under the same feature directory. Avoid dumping unrelated functions into
  shared files.
- **Preserve behaviour.** Always keep upgrade paths for Debian 10 and 11 working
  while modernising logic for newer releases. Add adapters or fallbacks instead
  of rewriting flows in place.
- **Comment new helpers.** Maintain the 1-in-10 comment ratio by documenting why
  the split exists and what each helper does. Favour short docblocks at the top
  of each file.
- **Re-run lint/tests.** After refactoring, execute `php -l` on the touched
  files and run `php scripts/lib/tests/development/Runner.php` so regressions
  surface before shipping.

These rules complement the “Linux kernel style” note already present in the
repository documentation and should be referenced before undertaking larger
clean-ups.

Orphaned Apps status/action wrappers, the terminal uptime formatter, and the
bootstrap date and OpenVPN renewal boolean wrappers have been removed after
repository-wide reference checks. Callers already use the shared status reader,
bulk action handler, version facts, and renewal plan directly; uptime remains a
numeric field in the terminal stats payload.

Read-only projections avoid redundant intermediate state: host load/PSI parsers
format their validated fields directly, resource counters merge the nullable
memory breakdown, and FireQOS returns its rendered template. Torrent-client
publication reuses its initialized per-client counters for membership checks;
cgroup status initializes its path map once. Validation, unavailable values,
field order and output bytes retain their existing contracts.

## Minimal Contract Rules (Joukahainen)

Network interface normalization rejects NUL bytes before trimming, including
raw default-route output. Sysfs and ethtool speed probes accept only decimal
values that fit a PHP integer; overflow retains their unknown-speed result.
Valid interface whitespace, speed values, command arguments, configured shaping
caps and detection fallbacks retain their contracts. `NetworkInfoTest` and
`NetworkInfoCharacterizationTest` cover malformed inputs and valid boundaries.

Stdout heartbeat startup rejects a failed or nonpositive parent PID before
building its liveness loop. A failed child-status read, invalid child PID, or
already-exited child returns `null` after stopping and reaping the child.
Shutdown ignores non-process resources without closing them. Valid starts keep
the inherited stdout, blank-line cadence, PID result, and shutdown behavior;
`StdoutHeartbeatTest` covers these boundaries with isolated process stubs.

The iostat collector checks the command exit status before parsing or publishing
captured output. Failed and timed-out probes may contain an initial sample;
they leave the current snapshot and histories untouched and use the existing
stdout diagnostic/zero-exit failure path. Successful captures and the legacy
string-returning runner callback retain their behavior. `DiskIostatTest` checks
failure statuses, missing status, and successful publication with isolated stubs.

Legacy per-user installer cleanup preserves the old installer when its crontab
entry is non-regular or cannot be read. A missing crontab, or one read
successfully without the old installer reference, retains cleanup behavior.

The account restart audit writer rejects empty or NUL-containing paths and
records that cannot be encoded as JSON before creating a directory or opening
the log. Valid records retain their existing JSON Lines bytes and append result.
It also rejects CR/LF bytes, dot segments, and symlinked ancestors before
directory creation, and rechecks the path after a new parent is created.

The file-backed storage benchmark reserves its random output name with exclusive
creation before preallocation or fio runs. A name collision fails without
touching the existing entry. Normal and shutdown cleanup compare device and
inode before unlinking, so a replaced path is left alone. Successful benchmark
jobs and their report format remain unchanged.
The read-only home-device probe parses md degraded counts as representable
unsigned integers. Malformed or overflowed sysfs values retain its existing
preflight failure result; valid counts keep the same report shape.

Boot-default convergence logs nonzero `/proc` remount and `update-grub` results
through the caller logger after managed configuration is persisted. Both steps
retain their fail-soft flow and successful command sequence.

Managed directory creation reports failure when its requested mode cannot be
applied, even if the directory exists. Existing successful calls retain their
directory and mode; callers can use their current failure path instead of
writing into a directory with unintended permissions.

Disk iostat snapshot publication validates all three output paths before the
first write. Symlinked targets or ancestors and missing parent directories use
the existing invalid-path failure result; ordinary snapshot and history bytes
remain unchanged.

The `/home` disk topology readers distinguish an empty slave list from a failed
slave scan. On scan failure, iostat retains its discovered-device fallback and
the BFQ probe returns its existing unknown result; a successful empty scan still
classifies a leaf device normally.
The BFQ probe also returns unknown when any backing member's scheduler is
missing or cannot be read and no other member proves BFQ active. A partial
read cannot prove that BFQ is inactive across the backing topology.
The BFQ probe follows stacked devices to leaf schedulers with a bounded walk,
and resolves partition members through their parent disks. Unreadable or
overdeep topology remains unknown rather than reporting BFQ inactive.
The shared block-device walk rejects `.` and `..` as device names before
building sysfs paths. Other valid dotted names retain their leaf result;
invalid names retain the incomplete-topology result without visiting nodes.

Nginx's fallback self-signed certificate command accepts only a valid host name
and quotes the complete OpenSSL subject as one shell argument. Valid host names
retain the same certificate subject; malformed host names skip the command.

Managed serialized file targets accept integer modes or decimal digit strings
within the Unix permission range (0 through 07777). Malformed or out-of-range
modes follow the existing failed-target callback path before any file is
replaced; valid integer modes and decimal strings retain their previous values.
`ManagedFileWriteSafetyTest` covers rejected modes and accepted forms.

Support SMTP response reads accept up to 1000 reply lines, including the final
status line. A peer sending more continuation lines raises an error instead of
keeping support mail delivery occupied indefinitely. Ordinary single-line and
multiline replies retain their status-code handling.

Incomplete user-maintenance markers warn on a failed or short write. Tracker
cleaner verbose logs reach the per-user append step only after the complete
temporary payload is written. Both retain their existing successful bytes and
failure cleanup paths.

Locale baseline writes likewise report creation or append success only after
the entire `/etc/locale.gen` payload is written. Failed and short writes warn;
locale generation keeps its existing best-effort sequence.

Watchdog and vnStat config rewrites also require the full payload byte count
before service activation or restart. Failed and short writes keep their
existing warning and skip paths.
When the watchdog uses an alternate device, an unreadable or unrewritable
installed configuration also skips activation; otherwise it could start with
the template's default device despite selecting a different one.

Locked PID and counter-state streams share one seek, truncate, complete-write,
and flush sequence. Their existing entrypoints and failure results are retained.

Strict device/inode snapshot comparisons share `pmssFilesystemStatSameInode()`
in the existing runtime filesystem facade. Callers retain metadata validation,
stat/open ordering, ownership, permissions, and mutation policy. Supplied stream
snapshots keep their null-field comparison; numeric lock comparisons retain
their explicit integer casts. The standalone updater remains self-contained.
The existing `pmssUserFileStatIsSingleRegular()` predicate also lives in that
facade, shared by readers, writers, staging, config guards, and identity cleanup;
its public name and single-link regular-file policy remain unchanged.

Blocking stream writers share `pmssStreamWriteAll()` for partial-write progress
and failure handling. Callers retain validation, flushing, closing, diagnostics,
and publication policy; empty payloads perform no I/O. Deadline-aware nonblocking
writers and one-shot complete-write checks retain their separate semantics.

Private temporary directory creation returns a path only after its 0700 mode
has been applied. A failed chmod or replaced symlink returns `null` and attempts
to remove the newly created directory; successful callers retain the same path
and private permissions, including under a restrictive process umask.

For any optimization/refactor touching existing CLI/API output, follow this
minimal loop before merge:

1. **Name the consumer**: State who parses the output (human, cron, WHMCS,
   callback, etc.).
2. **Freeze baseline behavior**: Capture current input -> output + exit-code
   behavior before changes.
3. **Refactor behind the contract**: Internal code may change, but default
   output contract must remain byte- and parser-compatible.
4. **Prove compatibility**: Add/extend tests that assert legacy contract
   behavior on the default path.
5. **Version if change is required**: Introduce an explicit new flag/path and
   keep legacy behavior as default until migration is complete.

Non-negotiable guardrail: machine-consumed stdout is payload-only. Any
diagnostics go to stderr/logs.

Reject the refactor if any of these are true:
- Default invocation output format changed.
- Default invocation exit-code meaning changed.
- Machine payload channel (stdout) now includes diagnostics/noise.
- No compatibility test proves legacy default behavior still works.

Command launch safety belongs before shell quoting and before `proc_open()`:
`pmssCommandCapture()`, `runCommand()`, and the shared process launcher reject
literal NUL bytes with the existing failure result shapes. They must never
execute the prefix before a NUL or log the malformed command. Empty shell
commands, shell syntax, and binary stdout/stderr retain their existing behavior;
`RuntimeTest` covers these boundaries for piped and inherited-terminal callers.

Systemd unit and action validators likewise reject NUL bytes before whitespace
normalization. Updater callers must validate the original argument before
trimming it, retaining existing invalid-unit/action skip paths. Valid names,
allowed actions, whitespace normalization, and generated commands are unchanged;
`SystemdRuntimeProcessesTest` exercises these contracts without service operations.

Process termination treats `pgrep -x` exit 1 as no match and other nonzero exits
as probe failures. A failed probe before signaling skips the kill; a failed
probe after a signal stops escalation instead of claiming the process exited.
Normal no-match, graceful-stop, and SIGKILL paths retain their prior behavior.
`UpdateRuntimeProcessesTest` covers both probe failures with stub commands.
The per-user watchdog PID reader also rejects zero and integer-overflow lines
from `pgrep` before returning process targets. Raw probe output remains available
to callers for diagnostics.

rTorrent process inspection and signal delivery share positive, representable PID
validation before integer conversion. Malformed types, NUL bytes, numeric prefixes,
and overflow cannot become process targets. Valid integer and decimal-string PIDs,
surrounding whitespace, normalization order, raw probe details, and repeated signal
calls retain their previous behavior. `RtorrentProcessTest` covers parsing and both
signal backends with recording stubs that never signal real processes.

Monthly IOPS enforcement uses the managed-user scan result, returning a nonzero
cron status when the scan fails or emits diagnostics. A successful empty scan
still returns success without changing any per-user state; `IopsLimitHelpersTest`
covers both outcomes with an isolated list command.

The current-process cgroup membership reader rejects NUL-containing paths before
calling `file()`. Malformed and unreadable paths return the existing empty entries
result, and path selection returns an empty string. Valid v1 and unified entries
retain their parsing order; `cgroupModeDetectTest` covers both paths with fixtures.

Config backup and prune helpers reject NUL bytes in source paths and service
keys before trimming, so malformed inputs cannot select an existing backup for
replacement or deletion. Rejections retain the null/no-op failure paths and
do not echo malformed bytes. Ordinary whitespace normalization, filenames, and
retention rules remain unchanged; `ConfigBackupsCharacterizationTest` verifies
both rejection and compatibility using temporary fixtures.

The shared log-path guard checks NUL, CR, and LF bytes before trimming, so
malformed paths stay on the existing false/empty read and append failure paths.
`LogWriteSafetyTest` covers leading, embedded, and trailing control bytes across
JSONL readers and both append helpers. Ordinary path whitespace normalization,
payload bytes, and successful log formats remain unchanged.

Root shell defaults validate their managed target before reading or replacing
it. Symlink and non-regular targets are left untouched, unreadable files are not
treated as empty, and atomic replacement preserves existing metadata. Success is
logged only after a complete write; `RootShellDefaultsSafetyTest` preserves the
historical content and skip behavior while covering rejected targets.

Rsyslog rate-limit candidates require a complete temporary write before syntax
validation. Validation exceptions still propagate, but the temporary copy of the
configuration is removed before control returns to the caller. Normal validation,
backup, and publication behavior remains unchanged; `RsyslogRateLimitTest`
covers cleanup and preservation of the original configuration.
The candidate must also acquire mode 0600 before validation. A failed mode
change removes the candidate and skips replacement, while the successful path
keeps the same bytes and backup behavior.

Managed-path backups validate the source before opening it and confirm the open
handle still names that regular file before creating a copy. A backup is usable
only after a complete copy, close, and mode application. The user slice drop-in
repair leaves its original file in place when backup creation fails; valid
repairs keep their content and backup format.

Snapshot log writers ignore invalid, closed, and non-stream handles without
consuming unrelated resources. Task cleanup tolerates a callback closing its
stream, so its return value or original exception survives and the prior umask
is restored. Valid streams retain the same newline-terminated bytes and warning
normalization; `RuntimeStreamSafetyTest` and `RuntimeTest` cover these boundaries.
Snapshot log tasks reject symlinked ancestors, redirected leaves, and non-regular
targets before append, then confirm the opened stream still matches the path
before changing its mode or invoking the collector. Valid paths retain their
append bytes, mode, lock, callback result, and umask restoration.
The task restores the prior umask in a nested `finally` even if closing the
stream throws. Close exceptions retain their existing propagation and precedence
over callback exceptions; normal callback results and append bytes are unchanged.

JSON Lines readers close their stream in `finally`, including when the handler
throws an exception or error. The original throwable propagates unchanged;
valid entries keep their ordering and invalid/scalar JSON is skipped. A read
failure before EOF returns `false`; normal EOF still returns `true`, including
empty files and a final line without a newline. Already delivered entries remain
available to handlers and the read/last convenience helpers. `LogWriteSafetyTest`
verifies these results and cleanup on read failures and handler exceptions.

Operator-side JSON readers share `pmssJsonDecodeAssoc()` for array-only decoding;
callers retain file guards, schema checks, and missing/malformed-data fallbacks.

## Helper Extraction Rules

Pikkumies preinstall markers and detached-job cleanup validate the entire state
directory path with the shared path guard before creating directories or touching
account files. Empty, relative, NUL-containing, dot-segment and redirected paths
use the existing false/null or updater skip results. Valid missing directories,
trailing slashes, marker names and leaf-symlink cleanup keep their prior behavior;
`PikkumiesPreinstallHookTest` covers rejected ancestors and unchanged file bytes.

Pinned remote installers share the version-match and guarded stale-binary
decision. The stale-binary helper handles absent paths as success; its path
safety and failed-removal checks remain in one place.

The Deluge command-link helper rejects a destination identical to its package
binary source before unlinking anything. NUL-containing paths also fail before
filesystem access. Normal `/usr/bin` to `/usr/local/bin` links and dry runs keep
their existing results; `DelugeCommandSymlinkTest` covers the rejected inputs.

Per-user nginx generation shares one guarded subdomain write and one final
primary-route write for active and suspended accounts. Their prerequisite
checks remain distinct: suspended users need a static template, while active
users need the torrent marker and a usable lighttpd port. An absent active
primary template still leaves the generated subdomain route in place.
`NginxConfigWriteGuardTest` locks the rendered routes and cleanup decisions.
Primary-route templates use the same token substitution for active and suspended
accounts; only active routes resolve port tokens, and unknown tokens remain intact.
Legacy Deluge port lookup runs only when the selected active template uses its token.

Pinned remote archive steps reject control bytes in archive names, source
directory names, and workspace paths before downloading or constructing the
destructive cleanup command. Valid names keep the existing extraction flow;
`RemoteBinaryHelperTest` covers rejected inputs without network access.

Torrent-client store scans reject group- or world-writable existing directories
in the managed path chain, even when ownership is correct. Ordinary private and
read-only directories retain their previous result; `TorrentClientsStoreTest`
covers rejection before installation.

The lighttpd watchdog's incremental nginx reader closes its log stream in
`finally`, including when metadata checks, state loading, seeking, or parsing
throws. Exceptions continue to propagate without publishing a new cursor.
Normal cursor bytes, partial-line retries, and recovery decisions are unchanged;
`LighttpdWatchdogNginxLogTest` covers exceptional cleanup and legacy behavior.
Failed reads, partial-line rewind failures, and unavailable cursor positions
leave the prior state untouched so a later run can retry the same evidence.
Complete lines before a partial final line still advance the cursor normally.

Counter-state updates release their lock even when reading prior state or
calculating deltas throws, as well as when persistence throws. The original
throwable propagates; normal deltas, payload bytes, and failed-open behavior
remain unchanged. `ResourceLogHelpersTest` injects read, decode, encode, and
write exceptions and errors, and verifies stream closure and lock reacquisition.

The shared directory reader rejects empty and NUL-containing paths before
calling `scandir()`, retaining `false` for scan failures across PHP versions.
Valid scans preserve ordering, numeric keys, literal names, and caller-owned
symlink policy. `RuntimeDirectoryEntriesTest` covers these boundaries with
temporary fixtures; an empty directory still returns an empty array.

Web-root reconciliation treats a failed directory scan as a failed traversal,
including during skeleton validation, copy, ownership application, and root
classification. Partial merges report a failed copy instead of proceeding to
state migration. `UserWebRootReconcileTest` covers unreadable skeleton entries.

rTorrent escalation markers are encoded before opening the destination. Failed
JSON encoding returns `false` without creating or truncating a marker; valid
payload bytes and retry timing remain unchanged. `rtorrentWatchdogDecisionTest`
covers malformed UTF-8, existing and absent markers, and legacy JSON escaping.
The shared marker writer reports success only for a complete payload write;
short writes return `false` like other write failures. It retains the existing
locking, empty-payload behavior, and marker bytes without retrying or deleting
partial state. The same test class injects failed, zero, short, and full writes
for scalar and escalation markers.

JSONL and timestamped log append helpers require the complete byte count,
including the newline, before reporting success. Short or zero writes return
`false` like other write failures; timestamped logging can use its existing
fallback and applies the optional mode only after a complete write. Partial
bytes remain in place: no truncation or retry risks overwriting concurrent logs.
`LogWriteSafetyTest` injects incomplete writes and checks successful byte output.
Operator-side watchdog state, pinned-version markers, CLI stubs and catalog
writers share `pmssFileWriteComplete()` with caller-owned path and cleanup rules.

Rootless Docker config publication rejects empty or NUL-containing targets and
requires a complete temporary-file write before metadata changes or rename.
Failed or short writes keep the prior `daemon.json` and remove the partial temp file.
`UserDockerCgroupDriverTest` covers both rejected targets and short writes.

Storage benchmark integer options and block-device/free-space command output
must fit a PHP integer before casting. Size options must also be finite and
below the representable integer limit. Oversized values retain the existing
input/command failure paths; ordinary decimal values keep their output shape.
`StorageBenchSecurityTest` covers the boundary and oversized inputs.

Regular-file integer reads reject decimal values above `PHP_INT_MAX` before
casting, returning the caller's default instead of a saturated integer.
Representable values, zero, and leading zeroes keep their previous results;
`RuntimeTest` covers these file-boundary cases.

System-stats PSI parsing accepts finite percentage averages up to 100 and
representable integer microsecond totals. Malformed or oversized fields render
as `na`, while valid fields keep the existing slash-joined order and formatting.
`SystemStatsCollectTest` covers the malformed boundary and legacy snapshot.

Systemd integer counter mapping rejects unsigned decimal values above
`PHP_INT_MAX` before casting, including values with leading zeros. Oversized
samples return the existing missing-sample result instead of a saturated counter;
representable values retain their integer mapping. The systemd slice property
characterization test covers both boundaries.

CLI diagnostic-and-return paths use `pmssCliReturnWithStderr()`, passing the
complete message and status unchanged; the helper does not exit or add a newline.

Whitespace column parsing belongs in `pmssConfigLineColumns()` from the runtime
library. Pass the existing minimum column count and `[]` for command output
whose `#` tokens are data; keep field validation and failure reporting in callers.
ARP and module tables use `pmssConfigRows()`. WireGuard peer blocks keep `# user`
markers with `[]`; account public-key lists filter `['#', ';']` while retaining
source indices for diagnostics. Monitoring rules and legacy port cleanup use the
default `#` policy. Default-route and running-peer readers keep every nonempty row.
LF/CRLF text readers use `pmssConfigTextLines()` and `pmssConfigTextRows()`;
array readers retain `pmssConfigLines()` and `pmssConfigRows()`. The text helpers
share the array parsers' trimming, source indices, internal spacing, inline
comments, literal `0` rows, minimum columns and split limits. Pass `[]` when
every nonempty line is data, including diagnostic stack traces beginning with
`#`. Readers using other newline rules retain their explicit splitting.
The rTorrent config, monitoring rules, default routes, disk inventory and
WireGuard public-key registry use these text helpers directly. Public-key
deduplication uses one insertion-ordered map; invalid-key diagnostics retain
their original source line numbers.

Parser compression retains source positions and result bytes. Certbot scans
backwards to the last summary marker; whole-file and tail reads share that path.
Cgroup fields consume their wanted map at the first numeric row, even overflow.
Quota normalization preserves trailing empty split fields,
and PSI fields share one unavailable-value path while keeping their existing bounds.
Both Certbot file-read branches enforce the requested byte budget, including
when file size is unavailable or the log grows after stat. Nonpositive budgets
return the existing empty-log summary before file-size or data reads. Stable
whole-file and tail summaries, read-failure summaries, and exception propagation
remain unchanged; `CertbotRenewalLogTest` injects these boundary conditions.

Systemd skip reporting belongs in `pmssSystemdActionSkip()`: pass the existing
reason and description, with `false` for legacy callers that do not record a
profile entry. Keep reason evaluation at its original point in each flow.

Systemd unit discovery matches the complete first column of `list-unit-files`
output. A unit whose name only begins with the requested service name does not
prove that the requested unit exists; on-disk and `systemctl cat` fallbacks
remain available.

When a helper pattern reaches three similar implementations, extract the shared
shape before shipping the third clone. The third implementation is the refactor
trigger, not proof that a duplicated pattern should persist.

Unsigned decimal values that must fit a PHP integer use
`pmssUnsignedDecimalIntParse()` from the runtime value module. It rejects raw
whitespace, signs, non-digits, and overflow before casting, while accepting zero
and leading zeroes. Callers retain their own defaults, minimums, and error text.
`pmssEnvReadDigits()` keeps its documented saturating cast contract.
The sysctl profile's sysfs NIC speed read uses this bounded parser; malformed or
oversized samples retain its 1000 Mbps fallback, while explicit environment
overrides keep their existing `pmssEnvReadDigits()` behavior.

The sysctl swap-device probe rejects NUL bytes and dot path components before
reading sysfs. Valid device names retain their rotational checks; malformed
names return the existing slow-device result.

### Third-Instance Refactor Trigger

When code review identifies that a proposed function has the same API shape as
an existing two-function pair, file the refactor issue first, or complete the
refactor if it is already tracked, and represent the third case as data or
configuration. The existing "extract at three call sites" threshold describes
when extraction is warranted; this rule defines when the work must happen. For
example, a proposed
`pmssEnsureXyzBlacklist()` sibling to `pmssEnsureAlgifAeadBlacklist()` and
`pmssEnsureDirtyFragBlacklist()` should become a registry entry behind one
shared blacklist helper instead of a third near-duplicate function.
