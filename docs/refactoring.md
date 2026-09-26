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

## Minimal Contract Rules (Joukahainen)

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

Snapshot log writers ignore invalid, closed, and non-stream handles without
consuming unrelated resources. Task cleanup tolerates a callback closing its
stream, so its return value or original exception survives and the prior umask
is restored. Valid streams retain the same newline-terminated bytes and warning
normalization; `RuntimeStreamSafetyTest` and `RuntimeTest` cover these boundaries.
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

## Helper Extraction Rules

The lighttpd watchdog's incremental nginx reader closes its log stream in
`finally`, including when metadata checks, state loading, seeking, or parsing
throws. Exceptions continue to propagate without publishing a new cursor.
Normal cursor bytes, partial-line retries, and recovery decisions are unchanged;
`LighttpdWatchdogNginxLogTest` covers exceptional cleanup and legacy behavior.

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

Systemd skip reporting belongs in `pmssSystemdActionSkip()`: pass the existing
reason and description, with `false` for legacy callers that do not record a
profile entry. Keep reason evaluation at its original point in each flow.

When a helper pattern reaches three similar implementations, extract the shared
shape before shipping the third clone. The third implementation is the refactor
trigger, not proof that a duplicated pattern should persist.

Unsigned decimal values that must fit a PHP integer use
`pmssUnsignedDecimalIntParse()` from the runtime value module. It rejects raw
whitespace, signs, non-digits, and overflow before casting, while accepting zero
and leading zeroes. Callers retain their own defaults, minimums, and error text.
`pmssEnvReadDigits()` keeps its documented saturating cast contract.

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
