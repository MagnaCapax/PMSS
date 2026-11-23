# ADR 0339: The panel serves its own jQuery 1.12.4 from the account's www

Date: 2026-11-23
Category: architecture

## Status
Accepted

## Context
Welcome, Configure, Apps and the local-frames head of `index.php` loaded jQuery 1.12.4 from
`ajax.googleapis.com` on every panel load. Google therefore saw a request from each account's browser
(with the panel hostname in the Referer), and a blocked or unreachable CDN left the tab bar and
`setHeights()` without jQuery. Liberty and privacy come first: a panel that tells a third party
about every visit is a privacy leak PMSS can close.

Delivery constraint (ADR 0022, ADR 0169): panel `.php` files reach existing accounts through the panel
heal, but a static asset such as `pmssTabs.js` reaches them only through the `update.php` per-user file
sync. A page healed ahead of its asset must not break.

The ruTorrent tree already ships the identical library as `www/rutorrent/js/jquery.js` (jQuery v1.12.4,
97163 bytes). It is not referenced in place, because ruTorrent upgrades are decided separately and
would move that file to another major version under the panel.

## Options Considered
- Reference `rutorrent/js/jquery.js` directly: no new file, but couples the panel to the ruTorrent tree.
- Serve the library through a `.php` shim so the heal delivers it: a new endpoint to maintain and a
  larger script response for no benefit over a static file.
- A byte-identical, panel-owned copy delivered by the existing file sync, referenced first, with the
  CDN as a fallback only when the local file did not load: chosen.

## Decision
`etc/skel/www/jquery.min.js` is a byte-identical copy of the bundled jQuery 1.12.4. It is listed in the
per-user manifest (`scripts/lib/update/users/filesystem.php`) beside `jquery.tabs.css`, so new accounts
get it from the skeleton and existing accounts on their next `update.php` run.

`welcome.php`, `configure.php`, `apps.php` and the local-frames head of `index.php` load
`jquery.min.js` and add one inline fallback, `window.jQuery || document.write(...)`, which requests the
CDN copy only when the local file failed to load. An account whose page was healed before its asset
arrived therefore behaves as before; once the file is present no request leaves the account's own host.
The version stays 1.12.4, so no panel script changes behaviour. The fallback is the only remaining CDN
reference for jQuery and is removed in a later change once the fleet has converged.

## Consequences
- Positive: Google no longer sees panel loads on converged accounts; the panel works with the CDN blocked.
- Positive: `SkeletonWebLocalAssetTest` already fails the build if a referenced local asset is not in the
  manifest or the skeleton.
- Negative: one 97 KB file per account home.
- Follow-ups: remove the fallback after convergence. `filemanager.php` (jQuery 3.7.1, other CDN assets)
  and the Chart.js script in `info.php` are separate work: no reviewed Chart.js copy is in the repository.

## References
- Issue #674; ADR 0022; ADR 0024; ADR 0169.
