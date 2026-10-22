# ADR 0151: One optional agent web login per account on the panel area

Date: 2026-10-22
Category: security

## Status

Accepted for default-off delivery; per-host enablement by operator marker.
Amends ADR 0053 (Security Analysis, "no second password store").

## Context

The operator, 2026-10-22: "we should have means to test user logins, so a
tooling to add a web login temporarily to user account, deterministc single,
and 16 character a-z0-9 password so impossible to guess and
pm-väinämöinen-<ABCEFDEFWEDFEWQADQWA> login name. not saved anywhere, and
autocleaned ... the simplest possible way to achieve this."

Support checks of an account's web pages (`/user-<account>/`) had to log in
with the customer's own password. That handles the customer's credential and
fails when the stored copy has drifted from the account. The panel zone's
`auth.require` admits exactly one name, `user=<account>`, so a second login
cannot be added to the htpasswd file without a config change.

## Options Considered

- Keep using the customer's password, as before. That handles the primary
  credential and fails on drift.
- `valid-user` on the panel zone: admits every line in the htpasswd file,
  including lines the customer added for other purposes.
- A fixed ASCII name for every account: departs from the operator's named
  form and gives all accounts the same name.
- One deterministic name per account, named in the panel zone's `require` only
  on hosts that opt in: chosen.

## Decision

`template.lighttpd` carries `##PMSS_AGENT_WEB_LOGIN_REQUIRE##` at the end of
the `/user-##username/` zone's `require` value. The renderer
(`pmssLighttpdRenderUserConfig`) replaces it with `''`, or with
`|user=<agent login>` when the host marker
`/etc/seedbox/config/agentWebLogin.enabled` is a regular file. The marker is
operator-set and never shipped, like `qbittorrentWebuiEnforce.enabled`
(ADR 0102) and `socket-table-privacy.enabled` (ADR 0068); tests point
`PMSS_AGENT_WEB_LOGIN_MARKER` at a fixture. Without the marker the rendered
`~/.lighttpd.conf` is byte-identical to the config before this ADR. The
`/webdav-<account>/` zone stays `user=<account>`.

The agent login is `pm-väinämöinen-` followed by 20 letters: the first 20 hex
digits of `hash('sha256', <account>)` (the input already used for per-account
ids in `nginxConfig/pikkumiesUi.php`), each mapped `0-f` to `A-P`. For
`testuser` it is `pm-väinämöinen-KOFNOLICCOANHBJJCJAA`. One function,
`pmssAgentWebLoginName()` in `scripts/lib/lighttpd/agentWebLogin.php`, defines
it; a tool that needs the name derives it the same way and checks it against
the rendered config. The name is not a secret.

PMSS writes, reads and removes no htpasswd line for this name. A separate
support tool appends one bcrypt line for it, running as the account
(ADR 0121), with a random 16-character `[a-z0-9]` password that is never
stored in plaintext, and removes the line when the check ends. That tool and
its cleanup are outside PMSS.

## Amendment to ADR 0053

ADR 0053's Security Analysis states that there is no second password store.
From this ADR: the account's `~/.lighttpd/.htpasswd` holds the account's own
entry and, only while a support check runs on a host with the marker, one
bcrypt line for the agent login. The account's own entry is not read, changed
or used by that path.

## Security Analysis

- What the line grants: Basic auth to everything under `/user-<account>/`,
  which includes the web console (`console.php`, a shell as the account), the
  file manager and the torrent clients. It grants nothing on `/webdav-`.
- Other readers of the file: the account's `rclone rcd` uses
  `--rc-htpasswd ~/.lighttpd/.htpasswd` and accepts any line in it, so the
  agent login also authenticates to that loopback rc while the line exists.
  The panel session-cookie login (ADR 0053) looks up only the account's own
  name, so the agent login works through Basic auth only.
- ruTorrent receives the agent name in `REMOTE_USER`. The shipped
  `etc/skel/www/rutorrent/conf/config.php` sets `$forbidUserSettings = false`
  and `$profilePath = '../share'`. In `php/util.php`, `getLogin()` lowercases
  that name and replaces each byte outside `[a-z0-9_-]` with `_`; `getUser()`
  selects it, and `getProfilePath()` creates
  `~/www/rutorrent/share/users/<sanitized agent name>/{settings,torrents}`.
  The agent starts with an empty ruTorrent profile rather than the customer's
  settings and saved torrent files; the underlying rTorrent account is unchanged.
- Exposure: a stolen password grants the account-level panel capabilities above
  and loopback RC access while its line exists, but no SSH/PAM login or root
  identity. Basic auth must use the existing HTTPS entry point. The outside
  tool must preserve private htpasswd permissions and must never log either
  password or Authorization header. The primary hash remains separate from the random
  service credential. Historical HTTP-auth parser flaws include
  [CVE-2011-4362](https://www.lighttpd.net/2011/12/18/1-4-30-faster-than-santa-your-first-present-this-year/)
  (fixed in lighttpd 1.4.30); this feature reuses mod_auth without changing its parser.
- Guessing: the name is public; the password has about 82.7 bits
  (36^16) and exists for minutes.
- Without a line, the extra `require` entry admits no one. The htpasswd file is
  account-owned, so only the account can add a line for that name, which gives
  it nothing it does not already have.
- Name bytes: the development test `AgentWebLoginTest` runs lighttpd with the
  rendered `auth.require` block and bcrypt lines for the account and the UTF-8
  name, and checks with curl: 200 for the account and for the UTF-8 name with
  their passwords; 401 for a wrong password, for the ASCII-folded name, without
  credentials, and for the UTF-8 name on `/webdav-`. This check must run on a
  testing machine; browser clients are a separate check. Tools must pass
  the name without shell quoting: `escapeshellarg()` drops the non-ASCII bytes
  when `LC_CTYPE` is `C`.

## Consequences

- Enabling: create the marker, then regenerate the account configs
  (`/scripts/util/userConfigLighttpd.php`); the lighttpd watchdog restarts each
  account's lighttpd because its config is newer. Enable on at most 2.5% of
  hosts per batch.
- Disabling: remove the marker and regenerate; configs return to the default
  bytes, and any leftover agent line no longer admits panel access. Remove the
  line as well to revoke the loopback rclone RC access described above.
- The outside tool removes the agent's ruTorrent profile directory during
  cleanup. `pmssUserTransferBuildRsyncFinal()` in
  `scripts/lib/userTransfer/remoteScripts.php` copies `www/rutorrent/share`,
  so cleanup must finish before a transfer or remove the profile from both
  source and destination regardless of a transfer. The main transfer excludes
  `.lighttpd`; the final copy includes only `.lighttpd/custom` and
  `.lighttpd/custom.d`, never `.lighttpd/.htpasswd`.
- `scripts/lib/user/recreateRestore.php` restores `.lighttpd/.htpasswd` from
  backup in full. An agent line present in that backup can be restored after
  the tool's cleanup and outlive it. It is a dead credential once the check
  ends because its password was never stored; the outside tool removes any
  leftover line for the deterministic name on every run before adding a fresh
  credential. PMSS does not expire or prune these lines itself.
- If a client cannot send the UTF-8 name, the prefix constant is the one place
  to change, and this ADR is amended.

## References

- `etc/seedbox/config/template.lighttpd`
- `scripts/lib/lighttpd/agentWebLogin.php`, `scripts/lib/lighttpd/configRender.php`
- `scripts/lib/tests/development/AgentWebLoginTest.php`
- ADR 0053, ADR 0068, ADR 0102, ADR 0121, ADR 0122
