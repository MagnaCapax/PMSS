# ADR 0052: update.php forces HTTP/1.1 on its git operations to avoid the GitHub HTTP/2 upload-pack challenge

Date: 2026-09-03
Category: infrastructure

## Status
Accepted

## Context
`update.php git/main` fetches source via `git clone` (and, for a pinned spec, a follow-up
`git fetch`). From some source networks GitHub's edge returns **HTTP 401
`www-authenticate: Basic realm="GitHub"`** on the anonymous `git-upload-pack` POST when it is
carried over **HTTP/2**, so `git` falls back to prompting for a username and, with no TTY, aborts
(`fatal: could not read Username`). The metadata `GET info/refs` returns 200 over either HTTP
version, and plain `curl`/HTTPS GETs succeed — only the git binary's HTTP/2 pack-fetch is denied.

Empirically proven (same-host config toggle, 2026-09-03, one PMSS host, git 2.39.5):
- `git config --global --unset http.version` (default HTTP/2) -> `git clone` 401, exit 128
- `git config --global http.version HTTP/1.1` -> `git clone` exit 0
Same host, same IP, same minute — only the config differs, and it flips the outcome. A config-less
control host 401'd at the same instant the HTTP/1.1 host succeeded. `git -c protocol.version=0/1`
does NOT help (it is the HTTP transport version, not the git wire-protocol version). This matches a
well-documented **generic curl-HTTP/2 <-> GitHub failure class** (curl issues #11201, #14923;
Atlassian "RPC failed result=56 HTTP 200"), whose universal workaround is `http.version HTTP/1.1`.
The block is IP/path-dependent (a different-ASN control node clones the same public repo fine over
HTTP/2), so it is not all networks — but wherever it fires, HTTP/1.1 dodges it.

The per-host workaround `git config --global http.version HTTP/1.1` is un-managed (PMSS writes no
git config; it exists only where manually typed, and vanishes on reinstall). ADR-0050 added a
codeload branch-tarball fallback when the clone fails — that keeps updates working, but leaves the
git-clone PRIMARY path broken on every affected host.

## Decision
`update.php` forces HTTP/1.1 on its own git operations by prepending `-c http.version=HTTP/1.1`
(constant `GIT_HTTP_VERSION_FLAG`) to the `git clone` in `fetchSnapshot()` and to the pin-path
`git fetch`. This restores the git-clone primary path (git/main stays git/main — a git clone, not a
tarball) on affected Debian-12 hosts with no per-host config, no credentials, and no global
host-config side effect. The ADR-0050 codeload branch-tarball fallback is retained as the backstop
for any other clone failure.

The fix self-propagates: a host on the codeload fallback pulls the new `update.php` (which now
forces HTTP/1.1), and every subsequent update uses the git-clone primary again.

## Options Considered
- A - keep the codeload fallback only (status quo after ADR-0050): the git-clone primary path stays
  broken on every affected host; every update pays the codeload tarball path. Rejected — git/main
  should be a git clone.
- B - have update.php write `git config --global http.version HTTP/1.1` on the host: a global side
  effect on the host's git for ALL usage, and it is exactly the un-managed per-host artifact this
  avoids. Rejected.
- C - retry with HTTP/1.1 only after the default clone fails: extra complexity for no benefit — the
  HTTP/2 challenge is known, HTTP/1.1 is harmless where HTTP/2 would have worked, so force it
  unconditionally. Rejected.
- D (chosen) - force `-c http.version=HTTP/1.1` on the clone + pin fetch unconditionally; keep the
  codeload fallback as backstop. Smallest change; per-invocation, no host state, no credentials.

## Consequences
- Positive: the git-clone primary path works on affected Debian-12 DC hosts with no per-host config;
  the fix self-propagates via the ADR-0050 fallback; no credentials, no global host-config change.
- Positive: harmless where HTTP/2 would have worked (HTTP/1.1 is universally served; the only cost
  is forgoing HTTP/2 multiplexing on a shallow depth=1 clone — negligible).
- Neutral: this is a workaround for a GitHub-side / network-path behavior, not a fix for the flag
  itself; the durable operator-side remedy (GitHub Support un-flag / authenticated egress) is
  separate. `codeload` remains the backstop if even the HTTP/1.1 clone fails.
- The transport choice is visible in the update log (`[RUN] git -c http.version=HTTP/1.1 clone ...`).

## References
- `scripts/update.php` — `GIT_HTTP_VERSION_FLAG`, `fetchSnapshot()` clone + pin-path fetch.
- `scripts/lib/tests/development/UpdateCodeloadFallbackTest.php` — asserts both git operations force HTTP/1.1.
- ADR-0050 (codeload branch-tarball fallback) — the retained backstop this complements.
- curl HTTP/2 <-> GitHub failure class: curl issues #11201, #14923; git `http.version` docs.
