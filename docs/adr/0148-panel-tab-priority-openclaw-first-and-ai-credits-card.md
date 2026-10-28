# ADR 0148: Panel tabs take a priority, OpenClaw-first accounts lose the ruTorrent tab, Welcome shows AI credits and news in two parts

Date: 2026-10-22
Category: domain

## Status
Accepted (operator-directed, 2026-10-22)

## Context

After ADR 0147 shipped, the operator asked for three things: "custom frames, the system we used to set those frames,
needs a priority system, basically. So open claw can be moved to the first one. And on OpenClaw instance, root
torrent, torrent clients should be default disabled. So just open claw first and first step of the welcome screen.
And also the dashboard should show Virsilipas tokens and credits if key is there." He had earlier endorsed a customer
suggestion that the Pikkumies ask bar show only with AI credits, with roughly how many questions they buy. On the news
list of ADR 0147 he wrote: "make certain that all the RSS feed sources are retained ... Maybe two segments, announcements
and vainamoinen articles. The vainamoinen articles need to be there, and I think there needs to be like 10 of them. Wiki
updates, probably not needed."

What the code did on 2026-10-22:

- Tab order was the primary frame set (remote `guiFrames.php`, or the local failover in `index.php`), then installed
  and proxied app tabs and the account's `~/.customFrames` lines in file order, Pikkumies spliced right after Welcome,
  Configure forced last. Nothing could move a tab. `~/.customFrames` lines are `appname|tooltip|label|url`.
- An account created with `--app-profile=openclaw` already has `~/.rtorrentDisable` (written at creation), so rTorrent
  does not run, yet the ruTorrent tab stayed in the bar: a tab for a disabled feature, which ADR 0021 #2 rules out.
- The OpenClaw profile skips the Pikkumies preinstall, so Pikkumies' own credits tool is absent exactly on the accounts
  the operator named. The account's AI key is `~/.virsilipasKey` (ADR 0090); `GET /v1/usage` on the credit service
  returns the balance, per-day prompt and completion tokens and the grants in one answer.
- ADR 0021 #3: the panel opens on Welcome. The tab script selects the first link, so Welcome must stay first.

## Decision

1. **Priority.** A `~/.customFrames` line may carry a fifth field, a whole number from -99 to 99. A line with a
   priority and an empty URL moves an existing tab without defining one (`openclaw||||50`). `index.php` sorts every
   tab after Welcome by priority, highest first, keeping today's order among equal priorities; Welcome stays first
   and Configure last. Defaults: Pikkumies 40 (its place today), OpenClaw 50 when `~/.rtorrentDisable` exists. The
   account's own lines win over both. Configure's custom tab rows get a Position field, and saving keeps the
   priority-only lines like it keeps comments.
2. **ruTorrent tab.** While `~/.rtorrentDisable` exists the ruTorrent tab is dropped (by key or by its `rutorrent/`
   URL), on both the remote and the local frame path. Stop in Apps writes the same marker, so a stopped rTorrent has no
   tab either; Start brings it back.
   The Welcome page does not suggest a torrent client to an account whose featured app is OpenClaw.
3. **AI credits card.** When `~/.virsilipasKey` passes the account agent's gate (regular own file, mode 600, a `vsk-`
   key, endpoint `https://virsilipas.mcx.fi/v1` or none), the status strip gets a fifth card, filled after load from
   `welcome.php?credits=1`: credits left, a bar of what is left of the newest grant, about how many Pikkumies
   questions that is on the default model (18,000 credits each, the published average) and the tokens used in 30
   days. The page calls only the credit service named in the key file, with a 4-second timeout, caches the figures
   for 60 seconds in `~/.cache/pmss-welcome/credits.json` (mode 600, no key in it) and serves a stale copy up to an
   hour when the service does not answer. A read that fails removes the card instead of showing an empty one.
4. **Ask bar: not in this change.** Showing the Pikkumies ask bar only with credits, with the question estimate,
   is built but not shipped: the bar only appears where the host's Eternals marker and the account's billing id exist,
   and no testing machine carried both on 2026-10-22, so its end-to-end checks could not run. The bar keeps today's
   behaviour. `welcome.php?credits=1` already answers the figures it needs, including the question estimate.
5. **News in two parts.** The News card (ADR 0147's single What's new list) shows the four newest announcements
   (WHMCS announcements feed, with dates and an All link) and the ten newest of Väinämöinen's articles (the panel feed's
   article category, title only). Wiki edits are no longer shown. Every row keeps its unread dot. Announcements keep
   their own direct read, cached 15 minutes per account.

## Consequences

- Customers can order their own tabs, and an operator or profile can move a built-in tab with one line.
- An account that switched rTorrent off in Configure also loses the ruTorrent tab, which is what ADR 0021 #2 asks.
- The Welcome page makes at most one credit-service request per minute per account, only for accounts with a key,
  and never during the first render.
- `index.php` and `welcome.php` reach existing accounts through the panel heal; `configure.php` and
  `configureSettings.php` through the update's per-user file sync. A Configure older than this keeps working; it
  drops priority fields when it saves custom tabs, until the update reaches the account.

## Alternatives considered

- Making OpenClaw the first tab and the landing tab: contradicts ADR 0021 #3 and the tab script; Welcome already
  features OpenClaw on these accounts.
- Reading credits through Pikkumies' `credits line`: absent on OpenClaw-profile accounts.
- A separate tab-order file: a second file for the same job; `~/.customFrames` is the system customers already use.

## Amendment 2026-10-28: Pikkumies included in the OpenClaw profile

[ADR 0197](0197-account-creation-app-profiles-as-data.md) supersedes the
creation-time Pikkumies exclusion described in the context and alternatives.
The data-defined `openclaw` profile enables both Pikkumies and OpenClaw, plus
rootless Docker, while keeping rTorrent disabled. Existing RAM and file-count
gates still apply, and the explicit Docker option takes precedence over its
profile default. The omitted profile remains the seedbox `default`.
The tab priority and credit-card decisions still depend on account state and
keep their existing behavior; profiles add no product-specific panel logic.
