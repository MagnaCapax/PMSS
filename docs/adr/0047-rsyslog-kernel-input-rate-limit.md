# ADR 0047: Rate-limit rsyslog raw kernel input

Date: 2026-08-19
Category: architecture

## Status
Accepted

## Context
PMSS already limits journald storage and message rate, and rotates Debian OS logs at
500 MiB. Neither control bounds a fast flood between logrotate runs when rsyslog reads
kernel messages directly through `imklog`: the raw input can write the same kernel record
to both `/var/log/syslog` and `/var/log/kern.log` without passing through journald's rate
limiter. Repeated OOM dumps have filled small root filesystems before daily rotation.

Debian 10, 11, and 12 all load `imklog` in `/etc/rsyslog.conf`. Their rsyslog versions
support module-level `RatelimitInterval` and `RatelimitBurst`. General output-action rate
limits are not available in those versions, and replacing the entire distro configuration
would discard release-specific and operator-owned content.

## Options Considered
- Rate-limit each file output action – closest to the disk-write boundary, but the reusable
  output rate-limit object arrived after the rsyslog versions on supported Debian releases.
- Replace raw `imklog` input with `imjournal` – routes messages through the existing journal
  limit, but requires owning the complete main configuration and changes kernel-log delivery
  semantics.
- Rate-limit the existing `imklog` module declaration – supported by every target rsyslog
  version and stops the flood before it reaches any local or remote output.

## Decision
Configure the stock Debian `imklog` declaration with `RatelimitInterval="10"` and
`RatelimitBurst="2000"`, matching PMSS's existing journald rate window and burst.

`/etc/rsyslog.conf` remains distro/operator-owned. Under ADR 0036's foreign-content
exception, update-step2 replaces only the single exact stock declaration and preserves all
other bytes. A custom or ambiguous declaration is left untouched with a warning. Before the
atomic replacement, PMSS validates a temporary full configuration with `rsyslogd -N1` and
creates a timestamped backup. Rsyslog restarts only after validation and replacement succeed.

## Consequences
- Positive: a raw kernel/OOM storm is reduced at the input and cannot continue writing at
  gigabytes per minute to both standard OS log files.
- Positive: ordinary userspace syslog input through `imuxsock` is unaffected.
- Positive: distro and operator configuration outside the exact stock declaration survives.
- Negative: kernel messages above the burst are deliberately discarded; the surviving first
  2000 messages per window retain evidence while protecting root filesystem availability.
- Negative: nonstandard imklog declarations require explicit operator reconciliation and are
  warned rather than rewritten heuristically.

## References
- PMSS #795
- ADR 0036 — PMSS-owned config generation and the foreign-content exception
- Rsyslog `imklog` module documentation: https://docs.rsyslog.com/doc/configuration/modules/imklog.html
- Debian rsyslog defaults: https://sources.debian.org/src/rsyslog/
