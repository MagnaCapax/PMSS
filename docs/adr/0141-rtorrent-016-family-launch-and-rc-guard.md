# ADR 0141: rTorrent 0.16 family launch and rc guard

Date: 2026-10-22
Category: architecture

## Status

Accepted

## Context

ADR 0130 curated jesec's static 0.9.8-r16 build and named newer upstream
rTorrent as follow-up work. Upstream rTorrent continued as the 0.16 series
(0.16.25, 2026-10-05). Running it against the PMSS rc template shows three
differences from 0.9.8:

- The template's `schedule2` and `network.http.max_open` names are deprecated.
  Without the `-D` command-line flag 0.16.25 refuses the rc file
  (`Command "schedule2" does not exist`). With `-D` the old names redirect.
- `encoding.add` was removed and `-D` does not restore it. 0.16 has a separate
  `system.file_name.allow_legacy_utf8` setting, which defaults to true. That is
  the 0.9.8 `encoding.add = utf8` behaviour: prefer `name.utf-8` and `path.utf-8`.
- Without a bind address, 0.16 opens its listen socket as IPv6. On a kernel
  without IPv6 this fails with `EAFNOSUPPORT`. PMSS hosts are IPv4 (systemPrep
  sets `net.ipv6.conf.all.disable_ipv6=1`), and 0.9.8 listens on IPv4 only.

The main thread is named `rtorrent-main` instead of `rtorrent main`.

## Decision

- Upstream 0.16.25 is built as one fully static musl binary, with libtorrent
  linked in and XMLRPC via tinyxml2. The build uses the source tarballs, whose
  SHA-256 matches the GitHub release digests. The binary is hosted add-only at
  `https://pulsedmedia.com/remote/pkg/rtorrent-0.16.25-static-amd64-r1`, SHA-256
  `84f2b40561f353249fa463d1f79a8d3f6c5ce886cbd974372d4e5553201e2f49`.
  Its catalog entry is `compatFamily: 0.16`, `isDefault: false`.
- `pmssRtorrentExecutorCommand()` appends `-D` only when the selected store
  version's projection entry has a numeric `compatFamily` of 0.16 or later.
  The system command and 0.9.8 selections launch exactly as before.
- The template replaces `encoding.add = utf8` with one `branch` line. It runs
  `encoding.add` when `system.client_version` is `0.9.8`; otherwise it binds
  `0.0.0.0`. 0.16 parses the line without resolving the 0.9.8-only command;
  jesec 0.9.8-r16 takes the first branch.
- `.rtorrentRestart.php` matches `rtorrent([ -]main)?`, so switching away
  from 0.16 stops it. The watchdogs already match the `^rtorrent` prefix, and
  the Configure switch identifies processes by executable path.

The stickz/rtorrent community fork (v7.2, rTorrent 0.9.8 with libtorrent 0.13.8)
is built the same way: static musl, XMLRPC via tinyxml2, async DNS via udns, from
the release tag tarball (SHA-256
`5091b54add2b913437512f4e10122423ada9408b2a101db2b6aae0814e569988`). It is hosted
at `https://pulsedmedia.com/remote/pkg/rtorrent-stickz-7.2-static-amd64-r1`,
SHA-256 `726f9ce28d2675425d45a624b95957b22f2777d4ccacf3634e5f9f7da3cf003c`, as
`0.9.8-stickz7.2` with `compatFamily: 0.9.8`. It reports client version 0.9.8,
so it takes the template's 0.9.8 branch and launches without `-D`.

## Consequences

0.16.25 is a customer-selectable version wherever the client store is enabled.
Switching between 0.9.8 and 0.16 is not a recorded major downgrade (both are
major 0 under ADR 0120). A user's `.rtorrent.rc.custom` still loads under `-D`;
a custom command that 0.16 removed outright stops that user's 0.16 rTorrent at
start, and switching back to 0.9.8 is the recovery. Accounts with no selection
keep `/usr/local/bin/rtorrent`.

## References

- Issues #1066 and #1068; ADRs 0105, 0120 and 0130
- [rTorrent 0.16.25 release](https://github.com/rakshasa/rtorrent/releases/tag/v0.16.25)
