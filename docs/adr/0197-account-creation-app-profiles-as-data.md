# ADR 0197: Account-creation app profiles as data

Date: 2026-10-28
Category: architecture

## Status

Accepted (operator-directed, 2026-10-28)

## Context

`addUser.php --app-profile=openclaw` was a special case repeated across CLI
parsing, service startup and post-provisioning. It also excluded Pikkumies,
contrary to the operator's requested app combination. The operator approved
`app-profile` and requested extensible definitions, a seedbox default, an
OpenClaw profile with Pikkumies and rootless Docker, and a storagebox profile
with storage apps and no enabled torrent clients.

## Options Considered

- More profile-name branches: small initially, but every new combination
  duplicates provisioning policy and can diverge from existing app controls.
- Arbitrary post-add scripts: flexible, but introduces another execution and
  validation boundary when all required app switches already exist.
- Profile data plus one app-switch registry: reuses the existing writers,
  user-config path and account launchers, keeping combinations independent of
  how each app is enabled.

## Decision

`scripts/lib/user/appProfiles.php` returns one array: profile name to app ID
to boolean. These keys are exactly the valid `--app-profile` values. Names
must match `^[a-z0-9-]{1,40}$`; invalid or unknown names fail parsing before
account creation. Omission selects `default`, meaning seedbox behavior.

| Profile | Enabled apps | Disabled apps |
| --- | --- | --- |
| `default` | rTorrent, Pikkumies, rootless Docker | none |
| `openclaw` | Pikkumies, rootless Docker, OpenClaw | rTorrent |
| `storagebox` | Pikkumies, rootless Docker, rclone, Copyparty | rTorrent |

Omitted apps retain their current defaults. No qBittorrent, Deluge or
Transmission enable marker is added by these profiles. PMSS knows apps and
profiles, never products; billing or product selection stays outside PMSS.

`scripts/lib/user/appSwitch.php` owns the creation switches. Config-backed
defaults flow into `userConfigApply.php` and its existing `--docker-enabled`
path before configuration. Explicit CLI settings win, and Docker's 245 MiB
floor remains authoritative. The generic profile walk runs in two phases.
The early phase runs after user config is applied, before service starts:
markers and deferred service starts. The late phase runs after post-provision,
where the Pikkumies and OpenClaw preinstalls always ran, because their gates
read the applied file quota and the installers need the account environment
(the reserved OpenClaw port file). Each app outcome is logged. Unknown registry
IDs log and skip at runtime; definition tests must reject them.

rTorrent off writes `.rtorrentDisable` with `disabled\n` as the account and
omits its startup; a failed disable retains the fatal boundary. Pikkumies
uses its existing preinstall command and 400 MiB floor. OpenClaw uses its
existing account/slice launch and gates: 2048 MiB RAM, 60000 measured free
file-quota slots, no existing `~/.openclaw`, and a present installer. Off
skips preinstallation. qBittorrent, Deluge, rclone and every toggle catalog
row with an `enable` key use their existing empty enable markers; false is
a creation-time no-op. Marker failure is logged.

All new home writes use `pmssReplaceAccountFile()` as the account, including
its symlink refusal and atomic replacement. Marker paths come only from the
registry or `pmssToggleAppCatalogRead()`; profile names never become paths or
commands. Catalog read failures log and leave its apps unavailable to the
walk. Existing installers retain credential generation, redaction, private
permissions, loopback binding and authenticated panel access. This change
adds no listener, public name, certificate or DNS record.

To add a profile, add one entry to `appProfiles.php` using existing app IDs
and booleans, then extend behavioral coverage for the combination. To add an
app, register its existing enable/disable mechanism in `appSwitch.php`; a
catalogued toggle app with an `enable` key is registered automatically. Reuse
existing gates and account helpers rather than adding another installer or
product-specific branch. Registry callbacks describe outcomes, optional
queued work and optional deferred service starts. Config-backed apps also
declare their existing config-store key.

## Consequences

- `--app-profile=openclaw` remains valid and now includes Pikkumies. Default
  seedbox policy is retained. New combinations require only profile data.
- Moves and recreates retain their existing flow; this is creation policy,
  not an ongoing profile reconciler or an existing-account migration.
- Automatic installs can still be skipped by resource gates. Marker enablement
  delegates service startup to the existing watchdogs and secured bootstraps.
- Hermetic tests cover definitions, default starts, both preinstalls, storage
  markers, rejected profiles, CLI precedence, resource gates, unknown apps and
  symlink refusal. Tests are written here for execution on a testing machine;
  local verification is limited to changed-file PHP syntax checks and static
  review, as directed by the task brief.

## References

- [ADR 0087: per-user OpenClaw installer](0087-separate-per-user-openclaw-installer.md)
- [ADR 0147: status-first Welcome](0147-welcome-page-status-first-home.md)
- [ADR 0148: tab priority and AI credits](0148-panel-tab-priority-openclaw-first-and-ai-credits-card.md)
- [OpenClaw operator guide](../openclaw.md)
- [Contracts](../contracts.md)
