# ADR 0088: Read-only Apps catalog tab in the customer panel

Date: 2026-10-07
Category: domain

## Status

Accepted

## Context

Customers ask which applications they can run and how. The operator requested
an Apps tab with Native and Docker links per application. The Welcome tab
already owns installation and service controls. The Docker helpers are not safe
to advertise as actions while #885 (non-root failure) and #886 (wildcard bind)
remain open.

## Options Considered

- Add controls to a new Apps page. Rejected: this duplicates the Welcome tab
  and exposes Docker actions before their known problems are fixed.
- Add a read-only catalog of links. Selected: it answers the navigation question
  without changing service state or duplicating control paths.

## Decision

`etc/skel/www/apps.php` is a read-only link page. Installed media-stack app
labels come from `pmssMediaStackPanelAppDefinitionsRead()` (ADR 0067), and
customer-managed app names come from `pmssCustomerManagedAppDefinitions()`.
Native links lead to the existing Welcome-tab controls; rTorrent and ruTorrent
are described as always on. Docker links lead only to documentation while
#885 and #886 are open.

The self-hosted list is a closed array mirroring the wiki page “Self-Hosted
Apps on PMSS.” Installation facts remain on the wiki. The local frame merge
adds the tab only when `apps.php` exists in the customer tree. The page requires
only delivered sibling files, following ADRs 0016 and 0022.

## Consequences

- The closed array needs updating when the wiki catalog changes.
- Category links depend on wiki headings. A test pins the seven anchor names.
- Existing accounts receive `apps.php` through the user-file update manifest.
- The tab retains Welcome as the default under ADR 0021.

## Relation to #673

#673 concerns controls in `welcome.php`. This page never duplicates those
controls; it points customers to them.

## References

- ADR 0016: customer PHP tree separation.
- ADR 0017: customer-tree PHP review checklist.
- ADR 0021: top-frame navigation contract.
- ADR 0022: delivered customer files and sibling dependencies.
- ADR 0067: media-stack panel app catalog.

## Amendment 2026-10-08

The operator rejected the read-only page because its installed-app rows only
linked elsewhere. The Apps tab now displays actual app and login state and
offers Open, install, recovery, secure, and managed-service toggle/restart
controls. These call the existing Welcome endpoints. Welcome and Apps load the
same delivered `pmssActions.js`, so request behavior (including qBittorrent's
password-sync prompt) has one implementation on both tabs. The media-stack
watchdog snapshot supplies per-app runtime state; unavailable snapshots are
shown as unknown rather than reported as stopped.

The 39 rootless Docker entries remain documentation-only while #885 and #886
are open. Their descriptions and caution tags are fixed display copy, and
their setup links stay within the existing wiki category anchors. This
amendment supersedes the earlier read-only decision and the #673 relation
above; the original rationale is retained as decision history.

## Amendment 2026-10-08 (2)

The Apps tab now offers live per-app controls. Installer-managed media apps use
their own tmux sessions for start, stop, and restart. A customer-owned
`~/.<app>Disable` marker records an intentional stop; `--start-stopped` skips
these apps, while `--start-app=APP` and `--stop-app=APP` act on one validated
installed app. The observe-only watchdog publishes `off` with zero failures for
marked apps, so a deliberate stop neither degrades the stack nor alerts. This
follows the default-on marker convention from #672 and rTorrent's existing
`.rtorrentDisable` marker. Opt-in qBittorrent, Deluge, and rclone retain their
`~/.<app>Enable` markers.

All customer state changes require POST and `X-Requested-With`. The Apps status
and bounded log reads remain GET. Native client stop signals only customer-owned
processes in the panel's mount namespace whose `/proc/<pid>/exe` resolves to the
app's native binary allowlist.
For Deluge's Python entry points, both the native interpreter executable and
the exact installed entry point in `cmdline` must match. Same-named container
processes are excluded. The qBittorrent password-sync
challenge remains on the shared action path. This narrows the impact of exposed
application credentials, a material concern for torrent WebUIs (for example,
qBittorrent CVE-2023-30801), without changing the account's credential storage.
See [NVD CVE-2023-30801](https://nvd.nist.gov/vuln/detail/CVE-2023-30801).

Install remains a whole-stack action. The seven media rows expose current
session state and app auth state, while the Docker catalog stays collapsed and
documentation-only. A stopped app can be started individually without waking
other intentionally stopped apps.

## Amendment 2026-10-08 (3)

The shared action JavaScript is emitted inline from guiv-delivered
`scriptsInc.php` by both Welcome and Apps. Guiv cannot deliver `.js` files,
so a guiv-healed Welcome page cannot rely on `pmssActions.js` being present
before a host has run `update.php` (ADR 0022). The action request behavior is
unchanged.
The toggle helper accepts both current callable handlers and the shell-command
strings passed by older qBittorrent, Deluge, and rclone pages. It retains the
POST and `X-Requested-With` gate. When an updated `apps.php` encounters older
guiv helpers during staggered delivery, it renders a short updating notice
with HTTP 200 instead of failing with an undefined-function error.

## Amendment 2026-10-08 (4)

The operator requested the "Find more apps" section open by default and a
broader LinuxServer.io catalog. A developer script builds a dated, closed
customer-tree snapshot from the LinuxServer.io images API. It excludes apps
already supplied by PMSS or the wiki list, images unsuited to shared accounts,
abuse-prone and circumvention-specific images, and deprecated or unstable
images. The 39 wiki entries and their guides stay in place. One search filters
both lists, including category names. The page omits the LinuxServer.io list
when the snapshot has not yet arrived during a staggered update.

The page warns that LinuxServer.io's published-port examples bind every
address, and shows a loopback bind instead. Each app still needs its own login
because other accounts on the host can reach loopback ports.

The Welcome and Apps tabs now render the same Your apps rows, read the same
live status, run the same action JavaScript, and serve the same read-only status
JSON from guiv-delivered `scriptsInc.php`. Welcome's separate service controls
and PHP restart queue are removed. Each page supplies its own status URL in
the section markup; the shared queue uses live JSON and restarts rTorrent via
`rtorrentRestart.php?action=restart`.

Media Start, Stop, Restart, and Show log buttons appear only when
`appsRuntime.php` is present. That file arrived with the matching
`mediaStack.php` actions, so a healed Welcome on an older host still displays
status and security information without offering unsupported controls.
`userMediaStackPanel.php` loads the update-only recovery command helper through
the guarded local loader, preserving the guiv dependency closure (ADR 0022).

## Amendment 2026-10-08 (5)

During staggered guiv delivery, `apps.php?status=1` and `?log=APP` return HTTP
503 JSON with an `updating` message when the older `scriptsInc.php` lacks their
emitters. The shared status poll displays that message in app rows and stops
retrying until the page is reloaded. Ordinary transient poll failures retain
their retry behavior.
