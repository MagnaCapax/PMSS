# ADR 0125: Account-owned vanilla Hermes

Date: 2026-10-20
Category: architecture

## Status

Accepted for opt-in installation. No account receives a vanilla Hermes runtime
until its own Configure action completes.
The 2026-11-04 amendment below supersedes the historical port-C, certificate,
service-identity and interface-choice routing decisions, in accordance with ADR 0150.

## Context

ADR 0128 reserves port 846 for one account-owned vanilla Hermes window. Each
account UI gets its own service origin and certificate. Root does not execute
or write account-controlled code (ADR 0121). Hermes' own login gates its
loopback listener; a configured public URL engages it.

## Options considered

- Each account UI uses a separate service origin and certificate.
- A customer `custom.d` proxy fragment was rejected because it could replace
  generated routing and would need a path prefix Hermes does not support.
- A generated port-C route with an account-side on-demand launcher keeps
  account processes under the account UID and puts the UI at `/`.

## Decision

The Configure card starts a pinned Hermes install as the account. The release
tag, commit, installer hash and package lock hash are verified before executing
upstream code. A checkout changed by the customer is left alone. The account
uses a separate random dashboard password and random session secret in a 0600
file; its primary password is never reused. The selected provider key remains
in a private `.env` entry referenced from `model.api_key` by environment name.
`model.base_url` is the supplied HTTPS endpoint unchanged.

The root-owned billing record supplies the same sha16 service permalink as
ADR 0128. Port C is `https://<sha16>.mcx.fi:846/`. Its nginx and lighttpd
blocks are generated, independent of the Eternals host marker and enabled by
the account's completed install. A root-owned default server rejects unknown
names. The port-C Hermes proxy clears Authorization, forwards HTTPS scheme, omits
query strings from access logs, rejects `/api/cron/fire`, and applies an exact
Origin check to writes and upgrades. GET `/api/*` requires Fetch Site
`same-origin` or `none`. Hermes' own login remains the backend gate. The
launcher checks listener ownership and probes for a 401 before opening it;
an ungated instance is stopped. The separate Agentboard mount forwards its
bearer Authorization header because that fork authenticates every API request
with it. Both mounts keep the same port-C Origin and Fetch Site checks.
Port C has two fixed pre-login selector redirects because its two mutually
exclusive UIs share one origin. Each selector only sets the HttpOnly UI-choice
cookie and redirects to `/`; it does not proxy an API request or expose account
content. The choice cookie has a `__Host-` prefix, and the proxy removes backend
cookie Domain scope. The Hermes login assets are the other pre-authenticated
paths. The generated Hermes mount uses an account-owned socket under `.hermes`;
an account-owned relay checks the TCP listener's UID before each connection.

The launcher starts Hermes web UI or standard Agentboard on demand as the
account. Agentboard uses a private Unix socket and single-use, 60-second
tokens in `~/.config/pmss/ui/tokens/` (0700 directory, 0600 files, eight live
tokens maximum). The chosen interface has one port-C browser origin.
Installer verbs use one account lock whose descriptor is closed on exec; no
Hermes cron supervisor is installed. The install action writes a status file
for the Configure card's five-second poll. Certificate requests originate in
the account process. Panel logout and account password change rotate the
port-C session secret and stop its running backend.

## Consequences and limits

The Hermes backend still binds loopback TCP. The Hermes login is required even
when nginx routing is correct.
Its login cookies are scoped by host rather than port; the port-B and port-C
Hermes windows share one browser cookie jar. Signing in to one can sign the
other out, as Configure discloses.

Vanilla Hermes is opt-in. The managed transfer excludes `~/.hermes` from its
hot main pass, stops the account dashboard before the final pass, and copies
the tree then. A customer-started, untracked Hermes process can still write
during that final copy; the transfer cannot freeze arbitrary account code.
No root process copies or mutates that tree for this feature.

The pinned release was checked against published upstream advisories. An agent
session acts with the account's permissions and provider key. The public
endpoint therefore requires the dedicated login,
origin checks, a private secret file, and an account UID process. No root
credential is exposed to Hermes.

## References

ADRs 0121 and 0128; `docs/contracts.md`, `docs/hermes.md`,
`docs/portManager.md`.

## Amendment 2026-11-04: Panel-path route

Hermes uses `https://SERVER/user-USER/hermes/` through the existing nginx and
account lighttpd panel route. This implements ADR 0150 Decision 3. The Eternals
marker and billing publication are no longer prerequisites for vanilla Hermes.
Both live port-846 writers are removed; setup removes the old default server,
and regeneration drops the old account vhost. Existing Pikkumies routes are
outside this change, except the shared Fetch Metadata map: it now refuses only
cross-site and same-site API reads on those routes too. Pure legacy rendering entrypoints retain their contracts,
but no production generator calls the port-C builders.

### Upstream evidence and pin

Source review on 2026-11-04 corrects the historical claim that Hermes did not
support a prefix. At
[v2026.9.24, f97608f](https://github.com/NousResearch/hermes-agent/tree/f97608f178d1ffeca59860195ab7da295f7c8e5f),
`hermes_cli/dashboard_auth/prefix.py` already reads `X-Forwarded-Prefix`;
`web_server_dashboard.py` prefixes the asset URLs in `index.html` and in CSS
(but not the lazy-page dependencies, below);
`web/src/lib/api.ts` prefixes API/WebSocket requests, and `web/src/main.tsx`
sets the router basename. Cookies support prefixed paths and `__Secure-` names.
However, `dashboard_auth/login_page.py` posts to `/auth/password-login`, and
`web/src/lib/api.ts` sends logout navigation to `/login`, outside the mount.

[v0.22.7](https://github.com/NousResearch/hermes-agent/releases/tag/v0.22.7)
was the latest published release inspected. Its
[password form](https://github.com/NousResearch/hermes-agent/blob/b200f5bc60e31588ba2cb60e288029c4a54ba5fa/hermes_cli/dashboard_auth/login_page.py)
uses `data-prefix` for login requests and landing URLs; its
[SPA API helper](https://github.com/NousResearch/hermes-agent/blob/b200f5bc60e31588ba2cb60e288029c4a54ba5fa/web/src/lib/api.ts)
prefixes logout. Upstream `tests/hermes_cli/test_dashboard_auth_prefix.py`
and `test_dashboard_auth_401_reauth.py` cover these flows. This is source
evidence, not a claim that upstream tests were run on the dev box.

The annotated tag object `592aa804d6ed135a18d6a01fb0824bfdbb0279d7` resolves
to commit `b200f5bc60e31588ba2cb60e288029c4a54ba5fa`. Downloaded commit source
verified the existing old hashes and these new SHA256 pins:

- `scripts/install.sh`: `abf338605dae6dd28d101673ac6aed7995c955745db46d47676bc591a7957209`
- `package-lock.json`: `cc006a0c37d86fa906164a5158207f8ad56aef4d4cd4b2aed7a6c58700070b8d`

The installer stage protocol changed: use `venv`, `python-deps`, `config`,
`products`, `complete`; upstream `products` now owns launcher publication and
frontend builds. Repository and system-prerequisite stages remain excluded.
The isolated dashboard flag prevents attachment to a different account-local
Hermes backend. An explicit reinstall stops the tracked old dashboard before
building/configuring its replacement, so the next Open starts with the new
URL and code. Customer-modified checkouts are still preserved.

### Lazy-page assets under the prefix

The source review above was not enough. A QA VM run of this change on
2026-11-04 found every routed dashboard page blank under
`/user-USER/hermes/`, in Chromium and Firefox. The HTML was prefixed, but
v0.22.7 builds with Vite's default base `/` (`web/vite.config.ts` sets no
`base`, and `scripts/build/web.mjs` passes none). Vite's preload helper turns
each lazy page's dependency into `"/"+path`, so those requests went to the
shared host root and 404ed. Rewriting them to the prefix in the test browser
made Chat and its WebSockets work, which isolated the cause.

PMSS cannot route root `/assets/`, because every account on the host shares
it. After upstream's `products` stage, `pmssHermesDashboardPreloadPrefix()`
changes that one helper to prepend `window.__HERMES_BASE_PATH__`, which the
dashboard already injects from `X-Forwarded-Prefix`. The dashboard still works
at a root mount (empty prefix). The function requires exactly one helper match
across `web_dist/assets/*.js`, refuses any other build, and does nothing on a
second run. `hermes_cli/web_dist/` is ignored by upstream git, so the checkout
stays clean and customer-change detection is unaffected. Remove this step when
an upstream release resolves its lazy assets at run time.

The same run measured the install at 93,036 new files and 2.54 GiB, with the
peak equal to the final count. The old 60,000 and 65,000 free-file gates let a
150 GiB account start and then fail in npm with EDQUOT. Both gates now use
`HERMES_REQUIRED_FILES` (100,000), and a failed install records a reason code
(`files`, `quota`, `error`) that the Configure card shows.

### Proxy and login decision

lighttpd retains panel authentication and clears Authorization after auth,
before forwarding through the existing account-owned, UID-checking Unix relay.
It strips only `/user-USER/hermes/` and supplies the fixed external prefix.
Its reverse URL mapping also prefixes already-prefixed response Location and
cookie Path values: see
[lighttpd 1.4.69 mod_proxy.c](https://github.com/lighttpd/lighttpd1.4/blob/lighttpd-1.4.69/src/mod_proxy.c),
`http_header_remap_urlpath()` and `http_header_remap_setcookie()`. The nginx
Hermes location normalizes that doubled prefix. No response body rewriting or
upstream source patch is needed; the one post-build change is below. Generated routes exist before installation,
so the creation gate can check hostname, reservation and both route configs.
A failed gate logs a skip and account creation continues.

Open keeps the single-use fragment handoff. Its customer-tree page builds every
URL from `location.origin`, never the current document URL containing Basic
user info. The relay supplies the prefix during password login and accepts
only the corresponding Secure, HttpOnly session cookie. Native Hermes login,
logout and all normal browser requests use the same prefix. Status polling
does not start a process or mint a token. Standard Agentboard lacks verified
prefix support: old selections fall back to native Hermes, and Configure
offers native Hermes plus eligible Agentboard Pikkumies. Old installs need an
explicit Install action to adopt the pin and URL; their card says Update needed.

### Security review and validation boundary

The [upstream advisory index](https://github.com/NousResearch/hermes-agent/security/advisories)
listed no published advisories during review; the CVE search found no matching
dashboard entry. The
[v0.21.6 security notes](https://github.com/NousResearch/hermes-agent/releases/tag/v0.21.6)
report forwarded-IP rate-limit bypass, unbounded login audit/body inputs,
native-login redirect takeover, repository git-filter execution and email
sender allowlist bypass. The new pin includes those fixes. The proxy replaces
client-supplied forwarded-IP metadata and keeps Origin/Fetch Site checks,
the cron-fire denial and query-free dashboard logging. Public aliases and
public vhosts hide upstream WWW-Authenticate so a tenant cannot replace the
shared origin's cached panel Basic realm. The authenticated panel still emits
its own challenge.

The blast radius of a dashboard session or provider-key compromise remains
the account's shell, files and AI credits. Account passwords are never stored
or reused; random dashboard password and session secret remain in a 0600
account file, absent from argv and logs. Tokens remain private and expire in
60 seconds. No root credential or new public name, DNS, certificate or listener
is introduced. Root reads bounded platform configuration and loads only its
own skeleton helpers; account code, installs and state writes run as the UID
(ADR 0121). The accepted shared server origin is the same boundary as console
and OpenClaw; cookie paths are not a separate browser security origin.

Repository tests cover route generation/remapping, gate failures and logged
skips, installer stages, Open and relay handoff, cookie attributes, and public
challenge suppression. Dev-box verification is changed-file PHP syntax only.
The testing VM must validate nginx/lighttpd syntax and exercise the browser
matrix in `docs/hermes.md` before production rollout; no production probe ran.

### Memory floor and Fetch Metadata (2026-11-04)

The Hermes install RAM floor is 768 MiB (`pmssUserHermesMinRamMiB()` and the
Configure install check), not the 1000 MiB copied from the media stack. It was
measured on a QA VM on 2026-11-04 with v0.22.7, whose installer builds the web
dashboard locally. At 400 MiB the build had not finished after 21 minutes:
the account sat at its MemoryHigh limit and the VM's swap filled. At 768 MiB the
install finished in about 11 minutes, with swap in use, and Open and chat worked
with no OOM kill. At 2000 MiB it took about 3.5 minutes. The floor gates only
the install: an account installed at 768 MiB and then resized to 400 MiB opened
and chatted normally. Running use measured about 320 MiB for the dashboard,
about 290 MiB for the gateway that starts with the first chat, about 125 MiB
for each open chat window and about 34 MiB for the relay. Account memory is a
MemoryHigh soft limit with MemoryMax at 1.25 times it, so pressure throttles
and swaps rather than kills.

The `/api/` Fetch Metadata rule refuses only requests the browser marks
`cross-site` or `same-site`. A request without `Sec-Fetch-Site` passes:
Chromium sends none on WebSocket handshakes, and the earlier allow-list form
refused every Hermes chat connection in Chromium. Upgrade requests stay behind
the exact-Origin check, which is the rule that closes the cross-site WebSocket
case.
