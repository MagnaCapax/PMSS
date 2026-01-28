# ADR 0005: Trust Boundaries for Internal Tools and Script Output

Date: 2025-12-10  
Category: architecture

## Status
Accepted

## Context

Historically, many PMSS scripts treated output from "trusted" internal tools
as inherently reliable. For example:

- `/scripts/listUsers.php` was assumed to always emit a clean list of
  usernames (one per line) based on `/home` and `/etc/passwd`.
- Callers such as `updateQuotas.php`, `setupNetwork.php`, `userTrackerCleaner.php`,
  and others consumed that output directly, assuming that if `listUsers.php`
  is ours, its output is safe to use as-is.

Production behaviour disproved this assumption:

- On a host where the PHP CLI lacked the `posix` extension, the new
  `/scripts/lib/user/userFilesystem.php` invoked `posix_getpwnam()` and began
  throwing fatals. Later, during an update, `/scripts/lib/user/*.php` and
  `/scripts/lib/users.php` were also removed while cron jobs continued to run.
  In these states:
  - `/scripts/listUsers.php` emitted PHP warnings, fatal errors, and stack
    traces rather than a clean username list. Real-world examples included
    lines such as:
    - `  thrown in /scripts/lib/user/userFilesystem.php on line 95`
    - `Stack trace:`
  - `updateQuotas.php` parsed that output into `$thisUser` entries and
    constructed shell commands from them, including attempts to operate on
    `/home/.quota` and other paths derived from fatal/error lines.
- This showed that even our own helpers can emit garbage when the environment
  is partial or broken (e.g., missing extensions, missing files, mid-update),
  and that downstream scripts must not treat their output as inherently safe.

We need a clear policy: **Internal scripts and tools are not implicitly
trusted; their output must be treated as untrusted data and validated at
every boundary.**

## Options Considered

- **Option A – Continue to trust internal tools by default.**
  - Pros: Less boilerplate; simpler call sites.
  - Cons: Already proven unsafe; breaks catastrophically during updates or
    partial failures; undermines safety guarantees.

- **Option B – Treat internal tool output as untrusted and validate at use sites.**
  - Pros: Explicit, localised safety checks; resilient to mid-update states
    and partial failures; clarifies trust boundaries.
  - Cons: Slightly more repetitive; requires discipline and tests to ensure
    validation is not accidentally dropped.

## Decision

We adopt **Option B** and formalise the following trust boundary rules:

1. **Internal tool output is untrusted by default.**

   - Scripts must treat output from internal helpers (e.g. `/scripts/listUsers.php`,
     `/scripts/util/*Status.php`, etc.) as untrusted input:
     - Always trim and validate data before using it in paths, shell commands,
       or database operations.
     - Never assume that "because it is our script" it cannot emit errors,
       stack traces, or unexpected content.

2. **Re-validate usernames and critical identifiers at each boundary.**

   - Any script that consumes usernames (regardless of source) must:
     - Trim whitespace.
     - Skip empty lines.
     - Re-validate using the core username validator:
       - `pmssValidateUsername()` enforcing `^[a-z][a-z0-9]{0,7}$`.
   - Callers must not rely solely on upstream validation (e.g., addUser.php
     or listUsers.php) and must enforce their own checks before acting.

3. **Defensive parsing of structured output.**

   - Where internal tools are expected to emit structured data (JSON, key=value
     lines, etc.), callers must:
     - Parse and validate the structure (e.g., JSON decode + type checks).
     - Treat any parse failure or type mismatch as a soft or hard error, not
       as "just another line" to process.

4. **Explicit handling of failure modes.**

   - Callers of internal tools must handle:
     - Empty output.
     - Non-zero exit codes.
     - Output that begins with or contains obvious error markers (e.g., `Fatal error:`,
       `Warning:`, `Stack trace:`) by treating it as a failure, not as data.
   - At minimum, this means:
     - Logging the failure.
     - Skipping the operation for that run or user.
     - Avoiding any destructive follow-up based on that output.

5. **Producer behaviour and tests.**

   - Producers like `listUsers.php` must also behave defensively when core
     dependencies or extensions are missing:
     - Exit non-zero on internal failure conditions.
     - Emit a single, clearly marked error line (or nothing) to stdout/stderr
       instead of full stack traces, to reduce the chance that callers will
       misinterpret error text as data.
   - Development tests must include cases where internal tools emit garbage
     (fatal errors, stack traces, empty strings) and assert that consumers:
     - Skip bad lines.
     - Reject invalid usernames and identifiers.
     - Avoid constructing shell commands or filesystem paths from them.
   - Static tests (source-level) should assert that key consumers of
     `listUsers.php` (and similar helpers) call `pmssValidateUsername()` or the
     appropriate validator.

## Consequences

- **Positive:**
  - Makes scripts robust against mid-update or partial-failure scenarios.
  - Clarifies that "internal" does not mean "always safe"; validation becomes
    a visible, enforced step at each boundary.
  - Encourages better error handling and logging when internal tools fail,
    rather than silently treating error text as data.

- **Negative:**
  - Slightly more boilerplate around repeated patterns (trim/validate/skip).
  - Requires careful review of existing scripts to ensure they are updated to
    the new trust boundary rules.

- **Follow-ups:**
  - Audit remaining call sites that use `shell_exec('/scripts/...')` or similar
    patterns and bring them in line with this ADR.
  - Expand tests to cover additional internal tools beyond `listUsers.php`.
  - Document this policy in contributor guidelines and code review checklists.

## References

- `scripts/cron/updateQuotas.php` historic behaviour and refactor.
- `scripts/util/setupNetwork.php`, `userTrackerCleaner.php`, `checkUserHtpasswd.php`,
  `userResourcesList.php`, and `userTorrents.php` revalidation changes.
- dev tests:
  - `listUsersConsumersGuardTest.php`
  - `listUsersGarbageOutputTest.php`
  - `usernameValidationTest.php`
