# ADR 0097: Pipe per-user lighttpd logs

Date: 2026-10-14
Category: architecture

## Status

Accepted

## Context

Per-user lighttpd wrote access and daemon errors directly to files on the
journaled `/home` filesystem. An append could wait for an ext4 journal
transaction inside lighttpd's single event loop, delaying unrelated requests.
Customers also use the existing access log when debugging custom web config.

## Options Considered

- Remove the access log: breaks the documented customer debugging workflow.
- Relocate logs: adds an account-writable path outside the existing quota.
- Change mount options: an append still changes file size and needs a journal
  transaction.
- Pipe both logs to account-owned append processes: retains the files and
  moves the journal wait out of lighttpd's event loop.

## Decision

Keep `mod_accesslog` and both existing paths. Set `server.errorlog` and
`accesslog.filename` to lighttpd piped loggers using
`exec /bin/cat >> /home/##username/.lighttpd/<log>`. The managed template owns
these directives under ADR 0032. Keep the hourly access-log trimmer.

## Consequences

Each account has one small logger process per log. The files remain in its
quota and receive appended lines. On Debian 10 and newer lighttpd releases,
the event loop's pipe write is nonblocking; lines can be dropped if a stalled
logger fills the pipe. A logger that ran longer than five seconds is restarted
immediately after exit; a shorter-lived logger is retried at the next
maintenance tick, up to 64 seconds later. Earlier lighttpd releases have
blocking pipe writes and do not restart dead loggers, so this guarantee does
not extend to them. The logger command runs under `/bin/sh` on releases through
1.4.69 and under the account's shell on 1.4.79; both accept this command.

The next managed config refresh and watchdog restart applies the change to
each account. This removes the direct log-write stall, but PHP and applications
can still write to `/home` during a request. Validate the rendered config with
`lighttpd -t` before deployment and measure request latency on a busy host
after deployment.
