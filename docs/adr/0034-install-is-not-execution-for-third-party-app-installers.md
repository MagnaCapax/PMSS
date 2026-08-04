# ADR 0034: Install is not execution — app installers must never run an installed binary

Date: 2026-07-29
Category: security

## Status
Accepted

## Context

`scripts/lib/update/apps/arr.php` detected the installed Servarr version by executing the
installed binary with `--version` / `-v` and parsing the output. The applications are
ASP.NET services that do not implement a version flag: an unrecognised argument is ignored
and the application performs a full Bootstrap instead. Radarr 6.2.0 states this in its own
log — `Bootstrap|Starting Radarr - /opt/Radarr/Radarr - Version 6.2.0.10390` — followed by a
database migration into `/root/.config/Radarr/`, from a process whose argv is literally
`--version`.

The probe therefore started a web application as root. When the updater exited, the process
reparented to PID 1 and survived as a permanent root daemon on a public port, running with
the Servarr first-run defaults `BindAddress=*` and `AuthenticationMethod=None` — an
unauthenticated, root-privileged HTTP API exposed to the internet. On 2026-07-03 that surface
was used to write a shim at a path the root process itself invokes, yielding root code
execution and a cryptominer implant on a production host.

The bug had been reported twice before and "fixed" twice in the wrong direction. The probe
first surfaced as an update **hang** (GH #526, #527; update.php parked reading a pipe that
never closed, holding `/run/pmss/update.lock` and stranding nginx for 25+ minutes). The
remedy was to bound the probe with a timeout. A timeout bounds how long the PARENT waits; it
does not bound the CHILD. Bounding therefore converted a loud, visible hang into a silent,
permanent root daemon — a strictly worse failure mode, and the direct cause of the compromise.

Measured fleet state (2026-07-29, 172 reachable hosts): 104 hosts had `/root/.config/<App>/`,
i.e. root had run a Servarr application on them; 281 root-owned configs carried
`AuthenticationMethod=None` and 285 carried `BindAddress=*`. Three long-uptime hosts still
had live root daemons; the rest died at reboot because the orphan has no unit.

## Options Considered

- **A — Keep the probe, kill the process group on timeout (`setsid` + `kill -- -PGID`).**
  Correctly bounds the child, but keeps the capability that caused the incident: root still
  executes an untrusted binary on every update, and an execution that completes fast enough
  is never bounded at all. Third attempt at bounding the same defect.
- **B — Delete the probe; detect the version from metadata only.** `pmssArrUpdate()` already
  writes `install_path/version.txt` after every successful install and already reinstalls
  when the version is unknown. Removing the probe therefore costs one extra reinstall per
  legacy install, once, after which detection is a file read.
- **C — Delete the module entirely.** Servarr is per-account software; `update-step2` already
  excludes `servarr.php` from the app autoloader (ADR-adjacent change ff6a5d76, GH #559), so
  the module is currently dormant. Rejected: it also deletes the legitimate install path, and
  the exclusion is a recorded deliberate decision, not this ADR's subject.

## Decision

**B, with a defence-in-depth layer.**

1. Version detection reads on-disk metadata only (`version.txt`, `VERSION`). The probe
   helpers, the probe timeout constant, and the `is_executable()` trust test are deleted, not
   bounded. `is_executable()` is a permission test, not a trust test — the install tree may be
   a leftover owned by a departed uid, as it was on the compromised host. Metadata reads
   reject symlinks so a marker planted in a foreign-owned tree cannot redirect root.

2. Returning "unknown" is safe by construction: the updater reinstalls, which is idempotent
   and writes `version.txt`, so a host pays the extra download once and then converges.
   Reaching that convergence required fixing a latent defect found while removing the probe:
   the release-asset regex captured the separator before the platform token
   (`6.2.0.10390.`), which never equalled the value read back from `version.txt`
   (`6.2.0.10390`), so the "already up to date" branch could never be taken. The captured
   version is now normalized through the same extractor used on read. Convergence still
   depends on the `version.txt` write succeeding; if it fails the host re-downloads on each
   update, which is a bounded cost and strictly better than the previous fallback of
   executing the binary.

3. `scripts/lib/update/arrRootExecutionBlock.php` makes a root launch impossible rather than
   making a root instance safer. It occupies `/root/.config/<App>` — the data directory the app
   derives from `$HOME/.config/<App>` when no `--data` is given — with a mode-0444 regular file.
   Measured 2026-07-30 as root on `/opt/Radarr/Radarr`: rc=134,
   `System.IO.DirectoryNotFoundException` during logger init, port never bound, and the file's
   sha256 unchanged afterwards (the app does not unlink what blocks it, even as root). The
   positive control — same binary, creatable data directory — starts normally, which is why
   `/opt` needs no permission change and customers keep full use of the shared install.
   A leftover config directory is moved to `<App>.pmss-disabled-<UTC>` and never deleted:
   an absent config IS the first-run condition, so deletion would regenerate
   `AuthenticationMethod=None` / `BindAddress=*` on the next launch.

   This SUPERSEDES the earlier config-hardening approach (`BindAddress=127.0.0.1`,
   `AuthenticationMethod=Forms`, random `ApiKey`). That approach was measured to work —
   unauthenticated API 401, `/` 302, valid key 200 — but it leaves a RUNNING root instance
   reachable from every local shell on a multi-tenant host, and the two are mutually exclusive
   because the block file occupies the directory the seeded config lived in. Prevention over
   mitigation; operator directive 2026-07-30: "it's not ever supposed to run as root, but for
   customers".

4. `scripts/lib/arrRootGuard.php`, called by `scripts/cron/mediaStackInstancesCheck.php` every
   two minutes as root, kills any process whose exe is under `/opt/<App>/` with real uid 0 —
   the backstop for an instance started before the block existed, or with `--data` pointing
   elsewhere. Never a cmdline match: customers run their own copies from `/home/<user>/.bin/`.

5. Legacy non-ARR probes with documented version commands remain behind the shared
   `remoteBinary.php` trust gate. It resolves the leading executable, requires a target and
   link target owned by root or a system UID, and accepts only non-writable paths under the
   standard system roots. A failed trust check returns the existing failed-probe result and
   never launches the command. This is defence in depth for compatibility probes; it does not
   weaken ARR's metadata-only rule.

**Generalised rule:** an installer may download, verify, extract and activate an artifact. It
may not execute it. Identify software from metadata — release data, a version file, a package
database, a checksum — never by running it. If a future probe of a potentially-daemonizing
program is genuinely unavoidable, it must run in its own process group and be terminated with
`kill -- -PGID` on timeout; an abandoned child is not a bounded probe, it is a permanent
orphan.

## Consequences

- Positive: root no longer executes third-party application binaries during updates. The
  class of defect — hang, orphan daemon, held update lock, unauthenticated root API — is
  removed at the source rather than bounded for the third time.
- Positive: an accidental root launch from any other path no longer lands on unauthenticated
  wildcard-bound defaults, and existing residue is repaired by the normal update flow.
- Negative: hosts carrying a pre-`version.txt` install pay one extra download per application
  on the first update after this change.
- Honesty about scope: loopback binding removes the remote attack surface only. On a
  multi-tenant seedbox every customer can reach `127.0.0.1`, so the hardened config is
  blast-radius reduction, not a security boundary. The boundary is that PMSS does not execute
  the binary. Servarr credentials live in the application database rather than `config.xml`,
  so the random credential seeded here is the API key — the machine credential the API
  authenticates with — not a UI password.
- Negative, accepted: an operator who deliberately ran a Servarr application as root would
  have authentication forced on at the next update, and no user exists in that instance's
  database to log in with. This is intended — root has no legitimate Servarr instance — but
  it is a real behaviour change, not a no-op, and is recorded here rather than left implicit.
- Follow-ups (operator-gated, deliberately not performed by this change): leftover
  `/root/.config/<App>/` databases and logs on affected hosts, and the dormant `servarr.php`
  entrypoint, are separate decisions.
- Follow-up (separate change, larger blast radius, must not be bundled here):
  `pmssCommandTimeoutTerminate()` in `scripts/lib/runtime/commands.php:104-122` calls
  `proc_terminate($process, 15)` then `proc_terminate($process, 9)`. `proc_terminate` signals
  only the DIRECT child, and nothing in the capture path signals the process group, so any
  daemonizing grandchild survives every timeout in PMSS — not just this one. Making timeouts
  kill the process group (`setsid` + `kill -- -PGID`) touches every timeout-wrapped command
  in the tree and needs its own review.

## References
- GH #526, #527 — the original hang and the timeout "fix" this ADR reverses in direction.
- GH #558 — timeout audit; #559 — Servarr removed from the system-wide update path.
- GH #760 — shared app-probe trust gate for legacy non-ARR version commands.
- `scripts/lib/update/apps/arr.php`, `scripts/lib/update/arrRootExecutionBlock.php`,
  `scripts/lib/update/apps/remoteBinary.php`, `scripts/lib/update/apps/rtorrent.php`,
  `scripts/lib/arrRootGuard.php`, `scripts/cron/mediaStackInstancesCheck.php`
- `scripts/lib/tests/development/ArrInstallerNoExecPolicyTest.php`,
  `scripts/lib/tests/development/ArrRootExecutionBlockTest.php`,
  `scripts/lib/tests/development/remoteBinaryHelperTest.php`
