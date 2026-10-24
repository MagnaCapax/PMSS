# ADR 0169: The panel tab bar: icons, one selected tab, keyboard, address-bar links, load progress and app state

Date: 2026-10-24
Category: domain

## Status
Accepted (operator-directed, 2026-10-24)

## Context

After the Welcome page rework (ADR 0146, 0147, 0149) the operator asked: "now we need to UX overhaul the tab selector
too, including the graphics etc. functionality, interactivity all the best goodies of modern day UX development !!! ...
so we wwant to increase usability etc. formatting, icons etc. all that good stuff, more intuitive, faster easier to
use, more informatinve".

What the bar was on 2026-10-24 (`etc/skel/www/index.php`):

- Identical dark-grey pills (`#3d3d3d`, Arial 14px), centred, a hover underline, no icons. The selected tab differed
  only by a 10% font-size bump from `jquery.tabs.css`, and not at all where that file is missing.
- Padding on both the link and its label made the tabs about 41px tall, while `#content` starts at 34px.
- Centred with no overflow handling above 760px, so a wide set of tabs was cut off on both sides and the left part
  could not be reached.
- No roles, no `aria-selected`, no keyboard support beyond Tab, and `jquery.tabs.css` removes the focus outline.
- The address bar never changed; a reload always returned to Welcome.
- A tab's app loaded on first click with no feedback.
- `setHeights()` and `loadFrame()` used jQuery, so a blocked jQuery CDN left the panel unsized (PMSS #674 class).

Constraints the change keeps:

- `index.php` reaches accounts through the panel heal; `pmssTabs.js` and `jquery.tabs.css` only through update.php
  (ADR 0022). So a new `index.php` runs next to old, new or missing copies of both, and next to whatever head the
  remote frame source serves (ADR 0024).
- In-page tabs, enabled features only, Welcome by default (ADR 0021); tab order from priorities, Welcome first,
  Configure last (ADR 0103, ADR 0148).
- Density: customers rejected bigger fonts and spacing on the Welcome page (#555, #1083).
- Other pages open tabs by clicking `a[href="#key"]` (`welcome.php`, `configure.php`, the Pikkumies wiring).

## Decision

1. **Look.** Every tab shows an icon and its label. The icons are a 25-symbol subset of Lucide 1.61.0 (ISC), inlined
   once per page; a fixed map from the lower-cased tab key picks the icon and any other tab gets a generic window.
   Labels drop leading symbols (the icon replaces Configure's gear) and an all-lower-case label is capitalised. The bar
   uses the Welcome page's colours: a dark bar with a bottom line, muted labels, a hover tint with brighter text, and the
   selected tab with a fill, an accent bottom bar, bold text and an accent icon. Configure sits at the right end while
   the tabs fit. Every rule is scoped under `#tabs`, so it wins over every `jquery.tabs.css` version.
2. **Geometry.** The bar is exactly 34px (44px on touch screens), a single row that scrolls sideways when the tabs do
   not fit, with arrow buttons on the side that has hidden tabs. `setHeights()` reads the bar's rendered height, so the
   content area always starts exactly under it. `setHeights()` and `loadFrame()` need no jQuery.
3. **Selection and keyboard.** The bar is a WAI-ARIA tablist: one `role="tab"` per tab, exactly one
   `aria-selected="true"`, panes are tabpanels, and only the selected tab is in the Tab order. Arrow keys, Home and End
   move focus; Enter or Space opens the focused tab (opening a tab loads its app, so focus never opens one by itself).
   The focus ring is visible. One inline script in its own `<script>` element owns this, whichever tabs plugin loaded;
   without a plugin it also shows and hides the panes.
4. **Address bar.** The address names the selected tab (`#rutorrent`); Welcome has no name. An address naming a tab
   that exists here opens that tab; any other name falls back to Welcome and is removed. This amends ADR 0021 #3:
   Welcome is the default when the address names no available tab. The address is replaced, never pushed, so Back still
   leaves the panel. A leftover `pmssr=1` is dropped from the address.
5. **Load progress.** While a tab's app loads for the first time (and while Welcome first loads) the tab's icon becomes a
   spinner and a thin line runs over the content. After 15 seconds the spinner stops and a short note offers to open
   the app on its own. Motion is reduced when the browser asks for it.
6. **App state.** Welcome and Configure already read app state for their own pages. They post it to the panel as
   `{type: 'pmss:apps-state', apps: {id: [word, tone]}}` from one shared word map in `pmssActionScriptJs()`; Welcome on
   every load (with its first alert title as `attention`), on its 30-second refresh, and when the panel asks on a tab
   switch (at most once a minute); Configure after each of its status reads. The panel accepts the message only from
   its own origin and from the Welcome or Configure frame. A tab shows a mark only when its app needs attention: a ring
   for a warning, a filled dot for a failure. The state word joins the tooltip and the tab's spoken name. Marks are
   cleared when nothing has reported for 10 minutes. No new request type and no new probe exist.

## Consequences

- Reloads after a save in Configure now return to Configure instead of Welcome.
- A new `index.php` next to an old `welcome.php` shows no app marks until the heal brings both; nothing breaks.
- The tests that pinned the old geometry (`SkeletonWebLocalAssetTest`) now pin the new one;
  `UserPanelTopFrameContractTest` checks the tablist, the icons, the selected tab and plain-text labels.
- The remote frame source must keep loading `pmssTabs.js` or a compatible plugin; the bar works without any plugin.
