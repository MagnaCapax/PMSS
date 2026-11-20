# ADR 0316: User transfer counts vanished source files as a completed pass

Date: 2026-11-20
Category: architecture

## Status
Accepted

## Context
`userTransfer.php` copies a live account. rTorrent and other account processes
keep deleting and renaming files while the main and final rsync passes run.
rsync reports a file that disappeared between listing and copying with exit
code 24 ("some files vanished before they could be transferred"). The transfer
returned failure for any non-zero main or final pass. A move whose last final
pass hit one vanished file therefore ended with exit 1. It recorded no
completion (ADR 0214) and skipped the source Eternals step, although every file
that still existed had been copied. The Docker hand-over pass already accepts
24 (ADR 0072).

## Options Considered
- Keep failing on 24: the account was moved correctly, but tooling that keys on
  the exit code reports it as failed and leaves the completion record unwritten.
- Accept 0, 23 and 24, as the Docker pass does: 23 also covers files rsync
  could not read, so a real gap in the copy would pass as complete.
- Accept 0 and 24 on the main and final passes: a vanished file is absent on
  the source too, so the target matches the source.

## Decision
`pmssUserTransferRsyncRcTransferred()` accepts exit codes 0 and 24. The
transfer fails when either the last main pass or the last final pass returns
anything else. Exit code 23 and every other code still fail, without a
completion record. The Docker hand-over pass keeps its own rule from ADR 0072.

## Consequences
- A live account move that only lost files the source itself deleted now
  completes. It is recorded as completed, so a repeat run from the same source
  is refused (ADR 0214).
- Unreadable source files (23), protocol, timeout and connection errors still
  fail the transfer.
