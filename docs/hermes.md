# Vanilla Hermes

Configure > AI agents offers an opt-in account-owned Hermes install. Install
and later opens run as the account. The installer verifies one upstream tag,
commit, install script and package lock before executing code. An existing
checkout with customer changes is preserved and installation stops with a
message. The account's primary password is not stored in Hermes; the dashboard
gets a separate generated password and session secret.
The dashboard starts with `env -i`, a pinned public URL, and credentials read
from its private 0600 account file; neither credential appears on argv.

Browser access uses `https://SERVER/user-USER/hermes/`, through the existing
panel login and host certificate, like OpenClaw. No Eternals host marker,
billing identity, account hostname, DNS record, public certificate or extra
public listener is needed. Port 846 is retired, including on marker hosts.
After the ordinary PMSS user update has reserved the loopback port and
generated the panel route, choose Install in Configure, or run
`~/bin/install-hermes install` as the account. The `aiappbox` creation profile
uses that same installer; it logs a skip if memory, measured quota, hostname,
reservation or generated routes are unavailable.

Hermes web UI is the default. Agentboard Pikkumies remains available when
Pikkumies is eligible. Standard Agentboard's sub-path support has not been
verified, so its previous preference and launcher links open native Hermes;
it is no longer offered as a separate vanilla interface. This does not change
Pikkumies eligibility or its existing routes.

The native dashboard starts on demand. Its own login still protects its
loopback listener. The generated lighttpd route remains inside panel auth,
clears Authorization before proxying, and supplies `X-Forwarded-Prefix`.
It uses an account-owned
socket in the private `.hermes` directory; its relay checks the TCP listener's
UID before each connection. The launcher checks the login gate at every open.
Open passes a single-use, 60-second token in a browser fragment. The handoff
constructs URLs from `location.origin`, spends the token, and receives Hermes'
own Secure, HttpOnly cookies scoped to `/user-USER/hermes`. Without a valid
token, the native login form accepts the separate dashboard password, visible
after confirming the account password in Configure. The account password and
the Authorization header never reach Hermes; with panel-session login on, the
account's own panel-session cookie does. The nginx route checks Origin on writes/upgrades and Fetch Site
on API reads; public routes hide upstream `WWW-Authenticate` challenges.

PMSS pins [v0.22.7](https://github.com/NousResearch/hermes-agent/releases/tag/v0.22.7),
commit `b200f5bc60e31588ba2cb60e288029c4a54ba5fa`. Source review found partial
prefix support even in the old v2026.9.24 pin, but its password form and logout
still navigated to root paths. The new pin fixes those flows without a local
upstream patch. Its installer uses `venv`, `python-deps`, `config`, `products`,
and `complete`; `products` builds the dashboard and publishes the launcher.
See the [ADR 0125 amendment](adr/0125-vanilla-hermes.md#amendment-2026-11-04-panel-path-route)
for source evidence, all four pins, and the security review.

Old port-C installs show **Update needed**. Install again to update a clean
PMSS-managed checkout and replace its dashboard URL. Customer modifications
still cause **Left unchanged**. Root only generates platform routes; checkout,
build, configuration and credential changes run as the account.

The account can use `~/bin/install-hermes status`, `start`, `stop`,
`repair-gate`, `reconcile-key`, and `uninstall`. `uninstall --purge` removes
account-owned Hermes state. No Hermes cron job is installed. Installation
status is shown in Configure; the card polls every five seconds while open.

During managed account transfer, the opt-in `.hermes` tree is excluded from
the hot main pass. The tracked dashboard stops before the final pass copies
it. An untracked customer-started process can still write during that copy.
See ADR 0125.

Validated on disposable QA VMs on 2026-11-04 (no Eternals marker, no billing
record, no production host): generated nginx and lighttpd configs pass, and an
account installs, opens, logs out, signs back in with the dashboard password and
chats in unmodified Chromium 145 and Firefox 146.
All dashboard assets load under the panel prefix (no root-path requests),
the three WebSocket handshakes return 101, foreign-Origin requests are
refused, the panel login realm is unchanged after a 401 and no port-846
listener exists. An account with too few free files
is refused at once, and Configure shows the reason. The install needs at least
768 MiB of account memory (ADR 0125); an account resized to 400 MiB after the
install still opened and chatted.
For about ten seconds after the first Open the Sessions page reports 503 until
the gateway has started; that is upstream first-run behaviour.
