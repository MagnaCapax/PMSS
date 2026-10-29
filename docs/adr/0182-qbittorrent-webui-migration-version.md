# ADR 0182: qBittorrent WebUI settings follow the build, with MigrationVersion fallback

Date: 2026-10-24
Category: security

## Status

Accepted.
This revision replaces the earlier MigrationVersion-only rule and 4.3.9 stamp.

## Context

qBittorrent 5.3 migrates 27 WebUI keys from `Preferences/WebUI/*` to `WebUI/*`
at migration 11, deleting the old names. Migration 9 removes the 74 aliases in
`handleSettingKeys`; migration 10 changes SMTP encryption; migration 11 also
moves export folders; migration 12 renames search persistence settings.
Older builds never reverse these changes. Builds from 4.4 onward stamp their own
migration number; 4.3.9 and older builds do not write `Meta/MigrationVersion`.
Consequently, a file at migration 12 can belong to a running 4.2/4.3 build.
SettingsStorage prefers a nonempty `qBittorrent_new.conf` left by an interrupted
save and rewrites the whole configuration on shutdown.

A WebUI listener may not appear until all torrent resume data has loaded. Its
absence within a short start wait does not establish a failure. Customers also
choose their own bind addresses and ports, including wildcard addresses; the
managed configuration deliberately preserves these choices. The hazard addressed
here is a wrong-section launch falling back to port 8080 and default credentials.

## Options considered

- Follow MigrationVersion alone: misidentifies a pre-4.4 build's active section
  after a 5.3 run and overwrites its subsequent edits.
- Stamp the migration number down to 3: replays unrelated upstream migrations,
  including proxy policy, and still cannot identify a system 4.2 build reliably.
- Identify the build and restore only the required names: chosen. Metadata is a
  fallback when the version cannot be read; PMSS never changes MigrationVersion.

## Decision

Readers use `[Preferences]` with the `WebUI\` prefix for builds below 5.3.
A 5.3+ build uses unprefixed `[WebUI]` keys only when MigrationVersion is at
least 11; before then Preferences remains authoritative until upstream migrates
it at the next start. Store builds take their version from
the published store entry. System and custom binaries run `--version` as the
account through `proc_open`, nonblocking bounded stdout and a two-second hard
deadline. Timeout kills the probe's process group, including descendants that
keep stdout open after the executable exits. An account-owned
cache under `~/.config/pmss/torrentClients/` is keyed by executable realpath and
mtime. A running process's executable takes precedence over the selected link.
Both sides read caches and last-build records through the bounded, verified
regular-file reader (128 bytes), rejecting FIFOs and links before opening and
verifying the opened inode. Customer PHP bundles its own copy (ADR 0016).
Root may read a cache but never probes an account binary itself. The trusted
account subprocesses also have timeouts: 30 seconds for restore/observation,
45 seconds for password synchronization, 5 seconds for a live throttle comparison,
and 75 seconds for stopped throttle convergence
(including Configure's 60-second stop wait). If discovery
fails, migration 11 or above selects WebUI; missing/malformed metadata selects
Preferences. Neither reader falls through to a stale value in the other section.

Managed writers retain the historical Preferences writes and additionally mirror
WebUI values when file metadata is at least 11. Below 11, the original insertion
order, line endings and keys remain unchanged. The operator and customer trees
carry matching section and restore functions, covered by parity fixtures without
crossing ADR 0016's boundary.

Before every PMSS start, while holding the account switch lock and while every
same-UID `qbittorrent-nox` is stopped, promote a nonempty pending `_new` save.
Then restore legacy names using the last observed build as the source:

- After a 5.3 run, copy present WebUI values back to Preferences; invert export
  directory enable flags, SMTP `SMTPS` to `req_ssl=true` (None/STARTTLS to false),
  and the two search persistence renames. Restore missing migration-9 aliases.
- Before a build below 4.4 follows a 4.4+ run, overwrite all old-name aliases
  from their new names. Copy the active WebUI values back too. Repeated pre-4.4
  starts keep the edits made in Preferences since that last run.
- Before a 4.4+ build follows a pre-4.4 run, overwrite every new alias from the
  old name, matching upstream migration 2's `handleSettingKeys<AddNewKeys>`.
  Convert numeric proxy type with upstream migration 3's exact table:
  0=None, 1=HTTP, 2=SOCKS5, 3=HTTP_PW, 4=SOCKS5_PW, 5=SOCKS4.
  Convert MaxRatioAction to ShareLimitAction with migration 7's table:
  0=Stop, 1=Remove, 2=EnableSuperSeeding, 3=RemoveWithContent. The inverse
  restores integer values before a pre-4.4 run. No profile or DNS policy is reset.
  For a 5.3+ destination, also overwrite WebUI from Preferences. Thus speed and
  password edits during a 4.3.9 period survive the return to 5.3.

The migration number is untouched in every case. Beyond those two value
conversions, PMSS does not replay upstream proxy profile/DNS migrations:
`Network/Proxy/Profiles/*` and
`Network/Proxy/HostnameLookupEnabled` stay intact. The GUI-only tray-icon change
is ignored. Track 1's numeric `lastRun` labels seed the last-build lookup;
`qbittorrent.lastBuild` records an observed release for all launches, including
system/custom builds whose Track 1 label contains no version. Failed/dead starts
never record the attempted build. Running watchdog observations also refresh it.
ADR 0120's major-downgrade refusal remains in force; config restoration is not a
general downgrade of torrent/resume data.

Upload throttling (#1165) uses the same discovered build: from 4.4 write
`BitTorrent/Session/GlobalUPSpeedLimit` in KiB/s, and below 4.4 write
`Preferences/Connection/GlobalUPLimit`. Unknown builds fall back to migration 2.
The watchdog compares only that active key before stopping, explicitly rechecks
liveness after SIGTERM, then restores and writes as the account under the switch
lock, before the existing start flow. A still-live daemon defers both write and
start to a later pass. `userConfig.php` also skips live throttle writes.
Without an active `.torrentThrottle` cap and without an account-owned
`qbittorrent.throttleApplied` record, convergence does nothing: no config read,
comparison, write or restart. An active cap accepts any value between 1 and the
cap, preserving a stricter customer choice. Unlimited zero, invalid values or
values above the cap require a stopped write. Record the cap PMSS actually
wrote, with mode 0600, under `~/.config/pmss/torrentClients/`.
On cap removal, delete an alias only if the active value still equals the
recorded cap and that alias also equals it. Preserve subsequent customer edits
and remove the applied record. Only a config change causes a restart; forgetting
a record after a customer edit does not. A repeated cycle never restarts again.
Only the root watchdog requests removal through its account-identity writer.
Customer starts never remove a cap or its applied record: an absent policy file,
an unreadable policy file (the canonical policy is root:root 0640), or a
nonpositive value leaves both untouched. A readable root-owned policy with the
account's group may still apply a positive cap. Root checks both policy and
applied-record leaves with no-follow `lstat` before invoking any account work;
when both are absent it returns the existing daemon state without a subprocess.
Throttle writes wait for the same 60-second stop interval as Configure and clear
an earlier throttle-deferred notice on success. Apps, Configure (including
rollback), and resolver launches apply the cap after restore while holding the
existing switch lock, before starting, so the next watchdog pass is a no-op.
Provisioning keeps the legacy template seed and applies the selected build's
key before first start. A successful template write also records its positive
cap through the private account writer, even if subsequent convergence is a
no-op; later root-side removal can therefore recognize the seeded cap.
The shared safe account writer creates qBittorrent.conf
as account-owned mode 0600; it is customer data, not a root-owned managed file
(ADRs 0046/0121). This fixes fresh throttled accounts being refused by the
account-side writer. No root chown inside the home is introduced.
Existing aliases are synchronized during stopped writes so a repeated pre-start
restore cannot reinstate the previous cap; they do not influence restart decisions.

The existing UploadThrottleConfig/UploadThrottleNeedsUpdate/ApplyUploadThrottle
APIs retain their exact-value contract through adapters over the same transformer
and stopped account writer. They seed both schemas for unknown legacy/uninitialized
builds and retain modern explicit zero on removal, including a daemon-saved zero.
The watchdog and customer launch paths use the cap/ownership policy above.
This distinction preserves main's regression assertions without duplicating the
throttle implementation. Known builds still choose their own active schema.
The credential subprocess API keeps stderr-only capture as argument four, followed
by optional timeout and operations; both timed and untimed calls inherit unused
console descriptors without seeking redirected logs.

Watchdog, Apps, Configure and failed-switch recovery, resolver starts, and update
post-setup share the account lock. Restore failures refuse starts and write a
plain private notice. A config leaf symlink is explicitly reported as
`qBittorrent.conf is a link; PMSS will not write through it`. Busy operations,
already-running processes and failed restoration have distinct notices. Apps
returns HTTP 409 with that notice. Each refusal replaces the previous notice.
Post-setup link repair waits up to 120 seconds for an ongoing start/switch, skips
restore while running and repairs a dangling selection even if restore fails.

This explicitly amends ADR 0106: normal watchdog starts pass through the resolver
even without a selected version, with `--system` retaining the system binary.
An invalid selected link never silently launches the system build. If the account's
`qbittorrentStart.php` has not arrived (e.g. over quota), the watchdog uses the
legacy `qbittorrent-nox -d` after trusted root-side restoration as the account.
Root preloads only trusted operator/skeleton code before dropping privileges;
new in-home work never runs as root (ADRs 0046/0121).

The bounded post-start wait and each running watchdog cycle inspect sockets
owned by the account's native process. A listener is unsafe **only** on port
8080 when that port is neither the account's assigned port, the WebUI port read
by the running build, nor its configured BitTorrent peer port. Wildcard,
non-loopback, IPv6 and other customer-chosen ports are left running. Stopped
PortEnsure retains account-port reconciliation; a running daemon is not stopped
merely because its owner changed the WebUI port. Customer addresses are not
added to the managed key set (ADRs 0102/0111).

An absent WebUI port is repaired upstream of PortEnsure, whose missing-port
rejection remains unchanged. When the config contains QSettings keys and the
assigned port is valid, the watchdog requests native SIGTERM before observing
the default listener. A still-live daemon defers repair and launch. Once stopped,
the existing account-identity restoration step promotes pending saves, then
upserts the missing port and address 127.0.0.1 under the switch lock. It rechecks
the authoritative build section and preserves present ports, credentials and
other settings. Empty, unreadable and linked configs do not request repair.
This restores the existing panel route without adding public exposure (ADR 0150).

Positive unexpected-8080 evidence stops the daemon, records a private refusal
marker and logs/notices the transition once. Subsequent watchdog starts remain
blocked, but stopped restore, PortEnsure and WebUiAuthEnsure still run. The
account clears the marker after the next build's section contains the assigned
port and a password hash. An explicit Apps Start/Restart or Configure choice can
also clear it. Each watchdog cycle observes once, without a sleep loop. The
post-start wait leaves a live slow daemon running without a stop or notice; an
empty PID set at its end fails. Configure additionally requires at least one
observation that the live process matches the requested selection before writing
lastgood, so an immediate exit or handoff to another build triggers recovery.
Customer-native TERM/KILL stops wait boundedly for the PIDs to disappear before
returning to a following start, which also checks that the account is stopped.

Password synchronization retries the same lock for up to 30 seconds, stops only
known native processes, restores pending settings and writes the hash as the
account. The write guard counts **all** same-UID qBittorrent processes regardless
of namespace or executable; an unmanaged remaining process is reported separately
from lock timeout. Failure retains the account notice and log, and changePw emits
a warning on stderr. It never changes changePw's system/HTTP-password exit-code
contract: these credentials have already changed and recreateUser/addUser must
not abort solely because optional qBittorrent synchronization failed. JSONL keeps
the separate `qbittorrent_updated` boolean, without contaminating stdout.

## Security and consequences

[CVE-2023-30801](https://nvd.nist.gov/vuln/detail/CVE-2023-30801) concerns default
WebUI credentials and command execution through the external-program feature.
Lost credentials can therefore expose account files and commands. PBKDF2 values
are copied without decoding or logging; no new primary credential is stored in
plaintext or passed in command arguments. The existing account-password sync is
a legacy contract; the separate WebUI prompt and Authorization stripping remain
unchanged (ADR 0111). Private config/cache/state/notice writes use mode 0600 or
preserve existing config metadata. Account writes allow regular non-symlink
leaves and symlinked parents within resolved home containment. Notices contain
no configuration values or hashes.

[CVE-2024-51774](https://nvd.nist.gov/vuln/detail/CVE-2024-51774) concerns HTTPS
certificate validation before 5.0.1. Per-build notes remain factual metadata,
not a claim that other versions are vulnerability-free; see ADR 0124. No DNS,
public certificate, proxy authorization change or listening port is added.
Customers can still launch independently; the watchdog observes rather than
asserting ownership over customer-chosen listener settings.

## Verification

Only `php -l` is run on the control node. Development tests are prepared for QA,
not claimed as passing. `QbittorrentPortRepairTest` covers missing-port recovery,
unchanged strict reconciliation, pending saves, live processes and busy locks.
Added/updated coverage includes section/build parity,
version cache invalidation, 5.3 → 4.3.9 → 5.3 speed/password preservation and proxy
privacy, live slow starts versus dead or mismatched builds, stopped refusal
recovery, once-only unsafe notices, post-KILL waiting, symlink notices, lock-wait
link repair and optional password-sync exit behavior. Existing catalog,
projection, note rendering, toggle and recovery tests remain in the QA scope.
Throttle coverage adds absent-cap preservation of a customer limit of 5000,
cap 500 preserving customer 300, unlimited/900 becoming 500 in exactly one
stop/write/start, removal only at the recorded cap, changed-value preservation,
and repeated-cycle no-ops. Tests also cover both-tree parity, Apps/Configure/
resolver prelaunch application, deferred notice cleanup, pending-save promotion,
and process/lock/write refusals. Further regressions cover unmigrated 5.3 auth,
password and port readers, stalled stdout descendants, FIFO/link caches, bounded
account subprocesses, account-owned provisioning, and all proxy/ratio enum cases.
The added suites are QbittorrentThrottleOwnershipTest, QbittorrentThrottleStartTest,
QbittorrentUnmigratedBuildTest, QbittorrentBuildDeadlineTest,
QbittorrentProvisionOwnershipTest and QbittorrentLegacyEnumTest. New switch
cases live in QbittorrentSwitchStartTest; TorrentClientsP1SwitchTest remains below
165 lines.

Sixth-pass coverage adds QbittorrentThrottleUnreadableTest (an actual root-owned
mode-0000 policy read as an unprivileged account, unchanged cap/record after a
customer start, and no restart on the next root watchdog comparison) and
QbittorrentThrottlePresenceTest (absent fast path, either present entry, dangling
links and a FIFO). Provisioning coverage checks the seeded applied record and
subsequent removal; command contracts pin the account-owned config write.

Billing heals its existing 33 panel filenames independently of nightly updates
(ADRs 0022/0099). Historical functions stay in healed files: native stop and
torrent-client toggle start remain in `scriptsIncAppProcess.php`; password sync,
apply, hash and preference helpers remain in `qbittorrent.php`. The size exception
is intentional: moving them into nightly-only files breaks mixed deliveries.
The abandoned AppStop/TorrentClientStart parts are removed from the loader and
delivery manifests. Healed files guard optional nightly includes with `is_file`
and calls with `function_exists`, retaining the legacy start/password path when
the new helpers are unavailable. The endpoint accepts an older toggle's void
success result. The nightly stopped-password helper checks that its healed hash
function exists before doing any configuration work. No billing allowlist change
is required. The broader dependency inventory still checks nightly delivery of
all customer-tree dependencies.
Configure's running-version reader has one definition, in
`configureTorrentClientsProcess.php`. Render fixtures stub that delivered
definition instead of appending a duplicate to its former file, assert the stub
target exists exactly once, and also render the delivered tree without function
replacement. Throttle-start fixtures prepare the state directory supplied by the
real account lock; a failed config write retains its detailed notice and applied
record. Section fixtures specify both the migrated metadata and build, avoiding
dependence on a testing machine's installed binary. Credential-transport coverage
exercises the bounded stdin backend with the same 45-second handoff deadline.
Projection coverage preserves CVE identifiers as `cve` metadata alongside optional
notes while rejecting the removed `badges` alias (ADR 0124).

Eighth-pass wait coverage injects clock/pause operations into both version probes,
the bounded credential subprocess, Configure's stability check and native shutdown,
and the socket safety shutdown. Configure's default switch callbacks propagate
the caller's operations instead of dropping them. Production deadlines and pauses
are unchanged. `QbittorrentBuildDeadlineTest` advances deadline time after its
fixture child starts, retaining real process-group cleanup and stdin assertions.
`QbittorrentWaitTest` exercises the full 60-second stop/throttle, post-KILL,
20-second observation and three-second stability budgets without real sleeps.
Existing lock-retry and account-stop tests already inject their pauses.
`QbittorrentPostSetupTest` uses the run hook to simulate the lock holder's exit
at dispatch, verifies the generated `flock -w 120` policy and runs the real link
repair without a fixed half-second sleep or polling child. Its shared fixture
copies the complete helper dependency closure. `PanelHealCompatibilityTest`
walks all 33 healed files, checks optional includes and the six historical helper
definitions, and exercises missing/old nightly helpers and an old void toggle.
Local validation is PHP syntax lint only; suite runtime and regression results
must be measured on a testing machine. No restored runtime budget is claimed
without that measurement.

Source checks remain for cron's top-level launch shell/delegation, the trusted
privilege-drop wiring, userConfig's account-writer call and changePw's top-level warning call, where no entry-point
ops seam exists. Launch, restore, socket, marker and convergence ordering use
injected behaviour tests. Identity delegation retains a narrow structural check.
QA must run the `pmss` and `pmss-render` profiles and the operator's twelve-case
`qbittorrent-webui-section-restore-and-notes-20261024.json` manifest. No end-use
result is claimed here. Manifest case B11's numeric 5.2.4-to-4.6.7 Configure
request conflicts with ADR 0120; operator reconciliation remains necessary.

## References

- [release-5.3.0 upgrade.cpp](https://raw.githubusercontent.com/qbittorrent/qBittorrent/release-5.3.0/src/app/upgrade.cpp)
- [release-5.3.0 SettingsStorage](https://raw.githubusercontent.com/qbittorrent/qBittorrent/release-5.3.0/src/base/settingsstorage.cpp)
- [release-5.2.4 SessionImpl upload-limit setting and KiB/s storage](https://raw.githubusercontent.com/qbittorrent/qBittorrent/release-5.2.4/src/base/bittorrent/sessionimpl.cpp)
- ADRs 0016, 0046, 0102, 0106, 0111, 0120, 0121 and 0124.
