# ADR 0103: Configure tab replaces Apps tab

Date: 2026-10-17
Category: domain

## Status

Accepted (operator-directed, 2026-10-17)

## Context

Account controls were divided between Welcome, Apps, and Info. The guiv heal
can deliver changes to those pages before the per-user updater delivers new
files. Some settings can run commands as the account or publish the username
in public records, so they need a stronger confirmation than an ordinary panel
action.

## Options Considered

- Keep controls on existing pages: rejected because customers must search
  several tabs for settings.
- Change the existing Apps page in place: rejected because its existing
  accounts and old endpoints need to keep working during staggered delivery.
- Add Configure with feature detection and retain old endpoints: selected.

## Decision

The new `configure.php` consolidates app controls and account settings. It
reuses the existing app renderer and catalog. It becomes the last in-page tab
only after both `configure.php` and `configureSettings.php` are present. Until
then, Welcome, Info, and Apps keep their legacy controls and appearance. The
updater manifest delivers the two new files together; the guiv heal set is
unchanged. `apps.php` redirects ordinary page visits after delivery, while
its JSON status and log endpoints remain available.

Settings writes use POST and the existing XHR guard. Saving custom tabs or
rTorrent directives, restoring the previous rTorrent directives, and
requesting a certificate with the username hostname additionally verify the
account password against the existing panel htpasswd hash. Writes reject
symlink targets and replace files through private same-directory temporary
files. The panel log records action names and outcomes only.

## Security review

The account password stays in the existing htpasswd hash store; Configure does
not copy it into a service config, response, or log. Deluge rotation uses the
existing separate service password. A leaked rTorrent custom file can expose
account-owned commands and paths, and editing it can run commands as that
account when rTorrent starts. A leaked custom tab file can reveal selected
links; a malicious link could steer the account browser. A username certificate
request publishes that username in permanent Certificate Transparency records.
All three therefore require password re-entry. Private 0600 files are kept in
the account home, not the public web root. The new endpoints add no network
bind or port. NVD's [qBittorrent CVE-2023-30801](https://nvd.nist.gov/vuln/detail/CVE-2023-30801)
illustrates how a torrent web UI with weak credentials can reach command
execution, reinforcing the existing separation of account and service secrets.

## Consequences

- Existing accounts keep their current UI until the per-user update delivers
  both files; mixed delivery cannot expose a partial Configure tab.
- The default certificate request still needs a service permalink, and the
  username option warns that Certificate Transparency publication persists.
- Existing Welcome endpoints and Apps JSON endpoints remain compatible.
- The app catalog has a transitional copy in Configure while old Apps pages
  remain available on hosts awaiting delivery; its content should be kept in
  sync until fleet convergence.

## Removal plan (added 2026-10-18)

The file-presence check is a transition path, not a permanent second flow
("One Flow, No Special Cases": an exception needs a removal plan). Remove it
on or after **2026-11-01**, and only once every host runs PMSS at or after
b1784bc5, so that every account has received `configure.php` and
`configureSettings.php` from its per-user update.

That host condition is the safety gate, not the date. `index.php`,
`info.php` and `welcome.php` are in the guiv heal set, so a copy with the
fallback removed reaches every healing account within a day of the daily
heal-master sync. On a host still waiting for the release, such an account
would get a Configure link with no Configure page behind it.

Each fallback site carries `#TODO(2026-11-01)`; list them with
`rg -n '#TODO\(2026-11-01\)' etc/skel/www`. Remove:

- `index.php` `pmssLocalFrameConfigureFrameRead()`: always return the
  Configure frame.
- `welcome.php` `$pmssConfigureAvailable` and the legacy controls behind it.
- `info.php`: the legacy shell console block; keep the Configure pointer.
- `apps.php`: redirect page visits unconditionally and drop the legacy page.
  The JSON status and log endpoints stay (see Decision).
- `statsHelpers.php`: the legacy app-control link branch.
- The transitional app-catalog copy noted under Consequences.
- Tests covering the fallback branches move to the single path.

## References

- ADR 0016 and 0017: customer-tree isolation and review.
- ADR 0021: in-page navigation, enabled features, Welcome default.
- ADR 0022, 0024, and 0099: staggered delivery and remote/local frames.
- ADR 0088: Apps tab decision superseded here.
