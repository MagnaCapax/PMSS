# Legacy Pattern Cleanup

## 1. Deprecated `die()` Usage
Found 82 occurrences of `die()` across the codebase. In PHP CLI contexts, `die("message")` exits with status 0 (success), which hides failures from monitoring tools and automation.

**Strategy:**
- Replace CLI `die("msg")` with `fwrite(STDERR, "msg\n"); exit(1);`.
- Introduce a shared helper `fatal(string $msg, int $code=1)` in `scripts/lib/runtime.php` to standardize this.
- Exceptions: `die()` in web scripts (under `etc/skel/www`) is acceptable as it terminates output for the browser, though `exit` is cleaner.

## 2. Direct Command Execution
Widespread use of backticks (`` `cmd` ``), `shell_exec`, `passthru`, and `exec` without error checking or logging.

**Strategy:**
- Refactor to `runCommand()` or `runStep()` (from `scripts/lib/runtime.php`).
- This provides consistent logging (`[CMD] ...`, `[ERR] ...`), captures stdout/stderr for debugging, and respects dry-run flags.

## 3. Inconsistent Logging
Scripts mix `echo`, `print`, and direct file writes to various log locations.

**Strategy:**
- Standardize on `logMessage()` (from `scripts/lib/runtime.php`).
- Ensure all cron scripts write to `/var/log/pmss/` with timestamps.

## 4. Hardcoded Paths & Magic Numbers
`/home/USER` is constructed manually in dozens of places.

**Strategy:**
- Use `pmssUserSkelPath` or a dedicated `UserContext` helper to standardize path resolution.
- Move configuration values (e.g., ports, quotas) to `etc/seedbox/config/`.

## Pilot Refactor: `scripts/cron/checkGui.php`
This script is a small, critical watchdog that exemplifies these issues.

**Refactoring Plan:**
1.  Require `scripts/lib/runtime.php`.
2.  Replace `` `cp ...` `` with `runCommand('cp ...')`.
3.  Replace `shell_exec` user listing with `users::listHomeUsers()` (from `scripts/lib/users.php`) to DRY up user enumeration.
4.  Add logging via `logMessage`.
