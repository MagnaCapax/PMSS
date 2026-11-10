# Security Testing Guidelines

Security for PMSS emphasizes defense-in-depth on a shared host.

## Negative Tests
- Invalid/edge inputs for parsers (spec parsing, config readers) must return safe defaults or explicit errors.
- File writes must fail safely (temp files, backups, atomic replace) and restore on error.
- Regular-file readers share `pmssRegularFileOpenVerified()` in `runtime/filesystemStream.php`.
  It snapshots or accepts an existing regular inode, opens with `rbn`, checks device/inode
  identity, and closes rejected streams. Callers retain path, owner, link-count, size,
  EOF and parsing rules; fault fixtures load the stream module beside their I/O shims.
- Masked file opens share `pmssFileOpenWithUmask()` in `runtime/filesystemStream.php`.
  Logging, staging, rebuild probes, restart audits and support snapshots retain their
  open modes, masks and path guards; fault fixtures load the real helper beside their I/O shims.
- Directory and support snapshot creation restore the caller's umask in `finally`,
  including when `mkdir` or exclusive `fopen` throws. `CreationUmaskSafetyTest`
  injects exceptions, errors and false returns without creating host files;
  successful modes, diagnostics and return values retain their contracts.
  Run these fixtures only on testing machines.
- Failed support snapshot writes and flushes attempt partial-file removal even
  when stream closure throws. `SupportSnapshotCleanupSafetyTest` injects single
  and combined failures with memory streams and intercepted unlink calls.
  Successful snapshots stay in place; write/flush throwables propagate when
  closure succeeds, while close throwables retain precedence when unlink succeeds.
  Run these fixtures only on testing machines.
- Local support mail owns the child and its pipes before validating pipe handles.
  Cleanup attempts process reaping even when a pipe close throws; a lone throwable
  propagates unchanged, while a reaping throwable retains normal `finally` precedence.
  `SupportSendmailCleanupSafetyTest` covers missing/invalid pipes, validation,
  write/read faults and combined close/reap faults with memory streams and fake
  process operations. Existing command bytes, message bytes and exit diagnostics
  remain covered. Run these fixtures only on testing machines.
- Network-affecting helpers (iptables rendering, FireQOS config) must degrade to no-op rather than partial application.
- Boot-default activation follows successful managed writes: failed fstab writes
  must not trigger a `/proc` remount, and failed GRUB defaults writes must not
  trigger `update-grub`. `BootDefaultsEnsureTest` covers refused targets, missing
  files, unchanged files, and successful updates with dry-run command profiles.

## Hermeticity
- Development tests MUST NOT mutate the real filesystem or network. Use temp dirs and environment overrides (e.g., `PMSS_OS_RELEASE_PATH`, `PMSS_APT_SOURCES_PATH`).

## Production Probes (read-only)
- Validate presence/versions of binaries and services.
- Confirm repo sources and apt metadata hashes when safe.

## Checklists
- Inputs validated and sanitized.
- Paths normalized; no traversal.
- Commands routed through `runStep()` with logs and rc checks.
- Permissions set conservatively; no world-writable artifacts.

