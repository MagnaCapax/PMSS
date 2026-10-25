# ADR 0146: rTorrent store default and fast-exit fallback

Date: 2026-10-22
Category: architecture

## Status

Accepted

## Context

Operator requirement (verbatim, 2026-10-14):

> "... multiple rtorrent versions, and the default binary is just a symlink to the version we default to, and people can change it from the apps tab"
> "we need to update to latest rtorrent as default too."

ADRs 0105 and 0106 restricted default-link creation to an explicit customer
switch. ADR 0141 made rTorrent 0.16.25 selectable with the required `-D` launch
flag and shared rc guards. Accounts that never chose a version should now get
the catalog default, while existing choices, including `system`, survive.
Customer `.rtorrent.rc.custom` files can contain commands removed in 0.16 and
cause immediate exits, so changing the default needs automatic recovery.

Bundled ruTorrent 3.10 also requires compatibility patches before these builds
can be offered again. A method-name census and real ruTorrent RPC traffic found
that 0.16.25 lacks `file.append` and the old proxy methods; conversion calls
without string targets, `group2` ratio setters, old port aliases, and untrusted
setter calls fail. jesec 0.9.8-r16's large API version selects ruTorrent's old
feature-bind port aliases even though those methods do not exist there.

The delivered, version-gated fixes preserve the system 0.9.8 behavior:

- Use `convert.kb` with an empty string target on 0.16 and later.
- Remove the API-version-based feature-bind port aliases; on 0.16.18 and later,
  map port range/random to `network.listen.port.*` and inert port-open to `cat`.
- Use `group.*` ratio setters with an explicit empty target on 0.16 and later.
- Map proxy getters/setters to `network.proxy.global(.set)` on 0.16.16 and later.
- Use trusted calls on 0.16 and later for fixed server-side commands, including
  `d.*`, `f.*`, `t.*` and `p.*` setters. Trust `setprops` only when every property
  is a bundled UI property. Read the fixed port getters through a trusted call;
  client-named getters stay in a separate untrusted request.
- Replace erasedata's `file.append` on 0.16 and later with `execute.nothrow`
  using shell arguments and double escaping for the nested rTorrent command,
  retaining the cleanup worker's list format.
- Map `dht` to `dht.mode.set` with its target on 0.16 and later.

The exact legacy-to-patched edits update existing account files through the
account writer. A customer-edited file may not match those edits, so automatic
default selection must verify compatibility evidence in the account's tree.

## Options Considered

- Keep every account on the system build until it switches: does not meet the
  operator's default-version requirement.
- Replace every default link: overwrites customer choices and risks restart loops.
- Seed only untouched accounts and fall back after repeated short store runs:
  chosen; reuses the store, account boundary and executor without new enablement markers.

## Decision

- Re-enable the rTorrent `0.16.25` and jesec `0.9.8-r16` catalog offers
  (`disabled: false`). Set `system` to `isDefault: false` and `0.16.25` to
  `isDefault: true`. All other catalog entries and fields stay unchanged.
- After publishing rTorrent's version links, load its validated catalog default.
  If it is a non-system build in a safe store directory, create
  `~/bin/rtorrent/default` pointing directly to that version directory. Only
  rTorrent gets an automatic link: for Transmission the default link is what
  marks it selected (`pmssLighttpdTransmissionSelected()`), so a seeded link
  would start a daemon the account never asked for. Both
  the missing-default check (including dangling symlinks) and the missing
  `~/.config/pmss/torrentClients/<client>.lastRun` check run as the account via
  the existing account-path command boundary. Use `ln -s` without replacement.
  A missing or invalid catalog skips default publication without failing convergence.
- Seed only if `.rtorrent.rc.custom` is absent (including no dangling symlink),
  or a regular non-symlink file whose SHA-1 matches the current skeleton's
  `/etc/skel/.rtorrent.rc.custom`. Root hashes only that skeleton and passes the
  literal digest into the account command; an unreadable skeleton skips seeding.
- Require regular non-symlink account copies of ruTorrent's `php/settings.php`,
  `plugins/httprpc/action.php` and `plugins/erasedata/init.php`, each containing
  its literal compatibility marker: the `convert.kb` call, the
  `$addReq = ($trusted && count($add))` fragment and `execute.nothrow={sh,-c,`,
  respectively. Define these markers once beside the compatibility patch entries
  in `scripts/lib/update/users/rutorrentRpcCompatibility.php`. All account file
  checks and reads run inside the command as that account, with every path and
  value shell-quoted. Root never reads tenant files to determine eligibility.
- The executor counts consecutive runs shorter than 20 seconds of the exact
  same resolved store command, including arguments. Three such exits select
  `/usr/local/bin/rtorrent` for subsequent launches and emit one fallback log
  line. A store run of 20 seconds or more resets the count; a different resolved
  command starts fresh. System fallback runs leave the failed command's state
  intact while the resolver keeps returning that command. State is in memory
  for the executor's lifetime; the selection link is never rewritten.
  Pure customer-side helpers decide the launch command and update the count.
  The executor loop, flock guard and resolver exception fallback are retained.
- Include the resolved launch command in the executor's `starting rtorrent`
  log line, making both seeded versions and system fallback visible.

This supersedes ADRs 0105/0106's "only an explicit switch selects a default"
rule for rTorrent only. qBittorrent and Deluge keep `system` defaults, and
Transmission stays opt-in, so their initial selections do not change. It also
supersedes ADR 0141's opt-in-only default policy for 0.16.25.

## Consequences

On hosts with `/etc/seedbox/config/torrentClients.enabled` (the small canary
set), eligible accounts that never switched receive 0.16.25 after link convergence
at their next rTorrent restart. Convergence does not restart running processes.
Edited custom rc files or missing compatibility evidence keep an unselected
account on the system build. A manually selected store build that exits rapidly
falls back to the system build after three fast exits. The Configure switch to
`system` remains available; its `lastRun` record prevents later convergence from
choosing a store default.

No new enablement markers, credentials, listeners or root inspection of tenant
selection state are introduced. The system rTorrent installer is unchanged.
A transient series of three short exits also triggers fallback until the
selection changes or the executor restarts; it does not prove an rc incompatibility.

Hermetic tests cover preserved choices, absent or matching custom rc files,
edited/symlinked rc rejection, unreadable skeletons, missing compatibility
markers and symlink rejection for all three ruTorrent files, Transmission
remaining opt-in, invalid or missing catalogs, and fallback count/command
boundaries. Run these only on testing machines; deployment and live canary
verification remain separate.

## References

- ADRs 0105, 0106, 0121, 0130 and 0141
- `scripts/lib/torrentClients/userLinks.php`
- `scripts/lib/update/users/rutorrentRpcCompatibility.php`
- `etc/skel/www/torrentClientsResolve.php`
- `etc/skel/.rtorrentExecute.php`

Host-marker opt-in clauses superseded by [ADR 0163](0163-torrent-client-store-on-by-default.md).

## Amendment 2026-10-24: 0.16.26 offered as opt-in

The catalog also offers static rTorrent `0.16.26` (`compatFamily: 0.16`,
`disabled: false`, `isDefault: false`) at
`https://pulsedmedia.com/remote/pkg/rtorrent-0.16.26-static-amd64-r1`, pinned by
SHA-256 `27a5150d55e6eeec7db95d4808cb51a32025e6171a23897b3a5ce173834cd3be`.
It requires an explicit customer selection; `0.16.25` remains the catalog
default. ADR 0141 records the custom rc guard for the removed storage log group.
