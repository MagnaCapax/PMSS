# ADR 0086: Root entry point for one account restart

Date: 2026-10-06
Category: security

## Status

Accepted

## Context

Billing needs to restart one account when its panel is unavailable. The old
`killall -9 -u` path stops customer-managed media-stack apps; their watchdog
only observes. A root entry point can also accidentally run customer-writable
code as root or interrupt an in-progress home rebuild.

## Options Considered

- Keep the forced kill: leaves media-stack services down.
- Make the media-stack watchdog restart apps: changes its policy for every user.
- Add a scoped root CLI with account-owned signals and starts: restores the
  selected account without changing watchdog policy.

## Decision

`restartUser.php USERNAME` validates the account and owned home, rejects a
suspension marker, then holds the recreate and restart locks through the run.
It reads real UIDs from `/proc`, preserves the user's service manager, and sends
TERM then KILL through `pmssBuildUserShellCommand()`. It starts rTorrent,
existing media-stack apps when marked installed, and lighttpd last. Other apps
remain with their existing check crons. It logs one JSON outcome per invocation.
After the detached rTorrent launch, it waits for a stable account-owned rTorrent
PID using the existing watchdog probe, which accepts the `rtorrent main` process
name. The account-owned media-stack start has a 300-second deadline and a
10-second kill grace. A timeout records a media-stack failure and leaves the
lighttpd start in the sequence; customer-script children may outlive that limit.
The account-owned media-stack start runs through `pmssBuildUserServiceShellCommand()`
so its apps enter `user-UID.slice` under the ADR 0023 service-launch rule; a
2026-10-07 production check found plain `su` had left them in root's session scope.

The media-stack command has one definition in the customer skeleton. The panel
loads its per-user copy; the root CLI loads only the root-owned `/etc/skel` copy
through a `scripts/lib` bridge. This preserves ADR 0016's `/scripts` boundary
and avoids executing a customer-writable PHP file as root. The customer's home
installer itself runs only as that account. No account or service credentials
are read or logged. This change does not alter service binds or web endpoints;
the existing lighttpd and media-stack exposure policy remains in force. The
lighttpd DoS history in [CVE-2022-30780](https://nvd.nist.gov/vuln/detail/CVE-2022-30780) reinforces keeping launch privilege
scoped to the customer account.

## Consequences

The billing backend must invoke the root-only CLI with one validated username.
A start failure is recorded but does not prevent later starts. A restart is
verified on a live host by checking that the account's rTorrent, media stack,
and lighttpd processes are running again and by reading
`/var/log/pmss/restartUser.log`.
