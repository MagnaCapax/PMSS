# ADR 0144: Media-stack login setup in a private network

Date: 2026-10-20
Category: security

## Status

Accepted

## Context

The B6 live checks confirmed shared loopback and available unprivileged
user/network namespaces on the Debian 12 canary. Hosts can disable namespace
creation, so every new setup must handle that failure at runtime. Existing
configured applications must retain their logins. ADR 0143 addresses the
equivalent container first-run flow separately.

## Options considered

- Seed on shared loopback when namespaces are unavailable: rejected because
  another account could claim the first user during setup.
- Use the account's existing tmux server inside unshare: rejected because
  that server would launch the app in its original network namespace.
- Run seeding and HTTP requests in one private network namespace with a
  dedicated tmux server: selected; no host firewall or new package required.

## Decision

The existing Bash installer serializes its function definitions and required
variables over stdin to `unshare --user --map-root-user --net bash -s`, enables
loopback, and uses a temporary private tmux socket. Generated passwords are
not supplied in process arguments. The namespace's tmux server is terminated
on exit, including failed API calls, before its temporary socket is removed.
Namespace/tool/loopback failures stop setup with a clear message; there is no
fallback. The internal dispatch marker is initialized by the installer rather
than accepted as an environment option. Existing configured-app checks happen
before namespace setup.

Autobrr reads `SELECT COUNT(*) FROM users` through sqlite3's read-only mode,
using the package already present in supported Debian baselines. An existing
single-user database skips creation. Empty databases can be seeded privately;
the count after successful create-user must be exactly one. Unknown counts or
multiple users stop setup without changing or deleting the database.

Jellyfin authenticates the new administrator, keeps the returned token in a
0600 curl config using `Authorization: MediaBrowser` with the login's client
identity and token, and reads the authenticated `/Users` list. This works when
Jellyfin disables legacy token headers. HTTP 200 and exactly one entry with the
expected name are required; failures report the HTTP status (000 when no response
was received). A private pending marker is created before
startup completion and removed only after verification, preventing failed
verification from being skipped as already configured on rerun. Failure asks
the customer to reinstall and retains config and user records. Successful
seeding returns to the existing normal service startup path.

## Review and consequences

The upstream review in ADR 0143 covers Jellyfin file-read, path-traversal and
local-network restart advisories, plus Sonarr's authentication-bypass history.
Autobrr's published advisory listing was also reviewed; an empty listing is
not evidence of safety. Its own database implementation uses the users table
for its user count. App credentials remain separate random service secrets;
they are not SSH/account credentials. Compromise of an app can expose files
and processes available to that account. No root permissions, binds, account
credential storage or customer/operator PHP boundaries change here.

Hermetic tests stub unshare, ip, tmux and APIs: they cover namespace/loopback
failure without fallback, private socket use and teardown, secret-free argv,
configured-app skips, exactly-one/empty/multiple/malformed user lists and
retained config/database bytes. bash -n, shellcheck and shfmt check the changed
installer. Real app startup and namespace support on other host classes remain
canary rollout checks; no live accounts were used during implementation.

## References

- [unshare manual](https://man7.org/linux/man-pages/man1/unshare.1.html)
- [Autobrr user repository](https://github.com/autobrr/autobrr/blob/develop/internal/database/user.go)
- [Autobrr create-user CLI](https://github.com/autobrr/autobrr/blob/develop/cmd/autobrrctl/main.go)
- [Autobrr advisories](https://github.com/autobrr/autobrr/security/advisories)
- [Jellyfin user API](https://github.com/jellyfin/jellyfin/blob/master/Jellyfin.Api/Controllers/UserController.cs)
