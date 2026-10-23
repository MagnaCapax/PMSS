# ADR 0147: Welcome is a status-first home: quick stats with the bonus among them, apps, connect, one news list

Date: 2026-10-22
Category: domain

## Status
Accepted (operator-directed, 2026-10-22)

## Context

The operator asked for a full user-experience overhaul of the Welcome tab: "the bonus % thing is so loud and middle of
things, outside of the quick stats", "the RSS feeds are all over the place in number # and formatting", "Show all the
relevant information, but not overwheling the user, elements at their sensible seizes", and he asked that the page fit
what PMSS has become: an app box, soon also sold with OpenClaw as the main app instead of a torrent client.

What the page was, measured on two fresh accounts on 2026-10-22:

- A full-width `BONUS: N%` banner at 2.4rem sat above everything (#494), and showed `0%` on accounts whose bonus is
  carried as additional disk (`.bonusQuota`).
- Three feed blocks with 4, 4 and 10 rows, two date styles and two list styles; "Wiki Updates" listed edits of old
  pages. Every render made three synchronous remote fetches (the 74 KB announcements RSS once, the 10 KB panel feed
  twice, plus the remote heading), with a 5-second timeout each, and the page reloaded itself every 180 seconds.
- A 2010-era "Basic Usage" block linking `https://www.putty.org/`, which by 2026-10-22 served unrelated political
  content under the title "Mike Yeadon: Final Warning".
- On an account created with `--app-profile=openclaw` (rTorrent off), the page said "just upload torrents to this
  directory" and "access ruTorrent", and mentioned OpenClaw nowhere.
- At 390 px the banner, the remote promo, an I/O notice and Basic Usage filled the first screen; quota started below
  the fold. The Pikkumies ask bar's status poll kept fetching every 5 seconds forever, also while the page was hidden.

A previous Welcome redesign lost density and was reported by long-tenure customers (#555), so density is a
requirement, not a style choice. A customer asked for a cleaner page as an opt-in (#1083).

## Decision

1. **Status first.** One status strip opens the page: storage (used / quota, burst limit when over), traffic over the
   rolling 30 days (with inbound, ratio from 1 GiB, bonus traffic and the port speed state), memory (process, cache,
   pressure) and server uptime. The host-wide load average is not shown: it is the server's run queue, not the
   account's load. Every figure comes from the readers that existed before (quota snapshot, traffic files, cgroup
   memory state); nothing is recomputed.
2. **The bonus sits with the quick stats.** It is a chip in the status strip header, linked to the bonus policy:
   `Loyalty bonus +N%` when `.bonus` records N, `Bonus disk +N GiB` for `.bonusQuota`, `Loyalty bonus 0%` only when
   the server records a zero, and `Loyalty bonus · how it works` when nothing is recorded. The host cannot tell base
   disk from bonus disk, so no bar segment claims to.
3. **Alerts only when they need the customer.** Storage health, over quota, over the traffic limit or throttled, and
   the RAM throttle or limit appear above the strip, dark-styled, with the existing policy wording. Nothing is shown
   when all is well.
4. **Apps are the main content.** Tiles from the existing app status reader (lighttpd and switched-off apps left
   out), each with a state word and Open (a new browser tab) or Manage (the Configure tab). An account whose main app
   is OpenClaw (OpenClaw installed, no torrent client switched on) gets OpenClaw as the featured tile and no torrent
   instructions. Quiet "Set up" tiles suggest apps the account does not run yet, completing the last row of three,
   and disappear at six apps.
5. **Connect card.** Host, username and port with copy buttons; links only to the PM wiki SFTP and SSH guides and to
   FileZilla and WinSCP, all checked live. No PuTTY download link.
6. **One What's new list.** Up to six rows in one format (type tag, title, short date): the two newest WHMCS
   announcements first, from their own feed so a busy blog or wiki never pushes them out (the reason a1d49030 kept
   the separate fetch), then the newest blog posts and at most two wiki changes, labelled as updates. Unread items
   carry a dot, remembered per account in the browser (all accounts on a server share one origin, so the key carries
   the account name). The remote message slot stays as a quiet tip card.
7. **Remote content is cached per account.** `~/.cache/pmss-welcome/`, 15 minutes, served stale when a fetch fails.
   A render with a fresh cache makes no remote request; otherwise the page paints and `welcome.php?news=1` refreshes
   the list. The heading slot uses the same cache.
8. **No background reload.** The 180-second reload is gone. The page reloads only when the customer returns after more
   than 10 minutes away and is not typing. App states refresh every 30 seconds and the Pikkumies poll runs only while
   the page is visible, and stops once the bar is ready.
9. **One layout for everyone, no toggle.** The guiv heal overwrites any `welcome.php` that differs from the delivered
   one, so a per-user "classic" copy cannot be kept anyway, and a second layout is a second flow (One Flow, No
   Special Cases). #555's lesson is met by density instead: 13 px body text, tabular figures, the strip and the first
   row of apps visible at 1366x640.

Unchanged: the endpoints `?status=1`, `?log`, `?backup=download` and `?restore=config`, the `?quota` frame contract
(ADR 0024), the pre-Configure controls while `configure.php` is absent (ADR 0103), the Pikkumies bar's eligibility
(ADR 0128). All new code lives in `welcome.php` and `welcomeAnnouncements.php`, both in the guiv delivery set; the
styles are inline because the heal does not deliver `screen.css` (ADR 0022). If the heal delivers the new
`welcome.php` before the new `welcomeAnnouncements.php`, What's new falls back to the old renderer.

## Consequences

- The operator's September spec of separate "Latest Writing" and ten-row "Wiki Updates" blocks is replaced by the
  single list; the full announcements, the blog and the wiki stay one click away.
- `webCgroupMemoryStatus.php` still defines the old RAM section builder (an old `welcome.php` delivered beside new
  helpers keeps working); the gauge builders it calls stay in `welcome.php` until that builder is removed.
- The remote heading text is still edited on the website side; the tip card styles any markup it carries.
- Feed-side duplicates (the same story syndicated twice) are the panel feed generator's to fix.

## Amendment 2026-10-23: three rows of common apps, one-click start

Operator, 2026-10-23: "now it's super heavy on the right pane and light on left .... atleast add to 3 rows of most
common apps and easy expandability to those with single click installs and management done, and then a link which opens
the configure tab", and "announcements should be 5."

- Item 4 changes: the apps an account runs come first, then the common apps it does not run (ruTorrent, qBittorrent,
  Deluge, Jellyfin, Sonarr, Radarr, Prowlarr, rclone, OpenClaw) until nine tiles fill three rows of three. The suggestion
  tiles that stopped at six apps are gone. Every other app the panel knows is under "More apps".
- A tile starts an app that is off or stopped with one click and restarts a running one, through the same endpoints,
  consent prompts and qBittorrent password prompt as the Configure tab's Apps list. A media app that was never installed
  says "Set up" and opens Configure, which installs the media stack. "All apps and settings in Configure" opens that tab.
- News shows five announcements.
