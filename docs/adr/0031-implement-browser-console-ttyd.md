# ADR 0031: Implement the browser shell console (ttyd) via the pinned-binary path

Date: 2026-07-11
Category: architecture

## Status
Accepted — supersedes ADR 0015.

## Context
ADR 0015 deferred GH #326 (HTML5 browser console) until a terminal gateway was
packaged, proxied inside per-user lighttpd, security-reviewed, and covered by
hermetic tests. The operator has now directed implementation to proceed and has
accepted the provisioning approach below (session 2026-07-11).

Two facts settle the gateway question:

- **ttyd is not a Debian package.** `apt-cache madison ttyd` is empty on every
  supported suite (verified on trixie). It therefore cannot be added to the
  `dpkg` selection baselines, and PMSS hosts no apt repository of its own.
- **PMSS already has a supported path for non-Debian binaries:** the pinned-URL,
  SHA256-verified installer pattern in `scripts/lib/update/apps/remoteBinary.php`,
  used in production by `rclone`, `syncthing`, and `filebot`. This is the
  established, reviewed mechanism — not an ad-hoc download.

This resolves the ADR 0015 tension: the "no ad-hoc runtime download" constraint
targeted a hand-rolled fetch, not the shared pinned-checksum installer that three
shipped tools already use.

## Decision
Implement GH #326 as an **additive** browser console:

1. **Gateway + provisioning (ADR 0015 #1, #2).** ttyd 1.7.7 (MIT), the pinned
   static amd64 binary, installed by `scripts/lib/update/apps/ttyd.php` mirroring
   `syncthing.php` — pinned URL, SHA256 `8a217c96…4f55`, arch-gated, idempotent,
   auto-loaded by the `apps/*.php` glob in `update-step2.php`. No ad-hoc download;
   same reviewed path as rclone/syncthing/filebot.
2. **Proxy contract (ADR 0015 #3; ADR 0009).** A per-user lighttpd block in
   `template.lighttpd` reverse-proxies `^/user-<user>/console/` to a private
   loopback **UNIX socket** (`~/.lighttpd/console.sock`) via `mod_proxy` with
   `proxy.header = ("upgrade" => "enable")`. WebSocket 101 upgrade over the unix
   socket was validated end-to-end (HTTP 200 + WS 101 + xterm.js render) before
   this change. nginx stays the lightweight front door (ADR 0009); no nginx edit.
3. **Launcher.** `etc/skel/www/console.php` runs under the per-user lighttpd —
   already as the customer UID — and spawns ttyd on demand. `info.php` gains an
   additive "Open console" button (ephemeral) and "Persistent (tmux)" button.

## Security review (ADR 0015 #4)
- **Authentication reuse.** The console path lives inside the htpasswd-protected
  `/user-<user>/` area (`auth.require`, `require => user=##username`). The panel
  login already gates it; no separate auth surface is introduced.
- **Privilege / blast radius.** ttyd is spawned by customer-tree PHP, so it runs
  as the customer UID — **never root**, no setuid, no privilege escalation. The
  shell it exposes reaches exactly the account the customer already reaches over
  SSH; the blast radius is identical to existing SSH access, not larger.
- **Loopback binding.** ttyd binds a UNIX socket in the customer's own
  `~/.lighttpd/`, never a TCP port; it is unreachable except through the
  authenticated lighttpd proxy. `--check-origin` rejects cross-origin WebSocket.
- **Command-injection surface.** No request data enters the spawned command: the
  mode is a fixed enum (`bash` | `tmux new -A -s console`); the base path derives
  from the process's own account name, never from request input.
- **Customer-tree separation (AGENTS.md).** `console.php` contains zero
  `require`/`include` and never traverses `/scripts/`.
- **Lifecycle.** `--once` exits ttyd on disconnect — no lingering per-user
  daemon. A persistent tmux session (opt-in) survives disconnect; ttyd is
  re-spawned on next open.
- **Privacy (MISSION #1).** Session I/O is **not** logged. GH #326's "tee output
  to a support log" idea is intentionally dropped — logging a customer's shell
  is peeking. Only spawn *failures* reach the user's own lighttpd error log;
  `console.php` keeps detached ttyd stderr private and emits a generic retry page
  when the socket is not created.

## Consequences
- Positive: mobile-only customers (iPhone/Chromebook) gain shell access with no
  SSH client — the #326 use case; unblocks #325 (1-click installer live output).
- Positive: uses the existing pinned-binary and per-user-lighttpd rails; no new
  module, no nginx change, no root component.
- Risk / follow-up: the php-cgi `setsid` detach of ttyd and the per-user socket
  proxy must be confirmed on a current-fleet host before wide rollout (the
  feature is additive and isolated, so a failure degrades only the console, not
  existing users). Fleet rollout via `update.php` remains an operator-gated step.

## References
- GH issue #326; supersedes ADR 0015
- `docs/adr/0009-nginx-lightweight-reverse-proxy.md`
- `scripts/lib/update/apps/ttyd.php`, `etc/skel/www/console.php`,
  `etc/seedbox/config/template.lighttpd`, `etc/skel/www/info.php`
- `scripts/lib/tests/development/BrowserConsoleArtifactsTest.php`
