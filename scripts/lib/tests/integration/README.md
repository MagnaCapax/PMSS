# Integration Tests

End-to-end verification tests that run on live PMSS servers. Unlike development
tests (hermetic, no side effects) or production tests (read-only), integration
tests **create and destroy real resources**.

## Requirements

- Root access on a PMSS server
- Server updated to git/main
- IPMI/out-of-band access (recommended — recovery fallback)

## Running

```bash
# Full watchdog verification (~5 minutes, creates temp user)
php scripts/lib/tests/integration/checkRtorrentWatchdogTest.php

# Skip torrent loading (faster, still tests watchdog cycle)
php scripts/lib/tests/integration/checkRtorrentWatchdogTest.php --skip-torrents
```

## Safety

- Tests create temporary users (prefixed `vtest`) and clean up on exit
- Cleanup runs via `register_shutdown_function` — covers crashes and interrupts
- No impact on existing users (separate processes, sockets, filesystem)
- Pre-test baseline recorded, post-test count verified

## Tests

| Script | What it verifies |
|--------|-----------------|
| `checkRtorrentWatchdogTest.php` | SCGI ping detection, 120s grace period, SIGTERM/SIGKILL restart, socket recreation, post-restart health |
