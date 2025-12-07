# Tracker Cleaner (rTorrent/ruTorrent)

This document explains the rTorrent tracker cleaner used on PMSS hosts: what it does, why it exists, and how users can control it.

## Purpose
- Keep rTorrent responsive on shared hosts by removing known bad/dead public trackers from `.torrent` files that rTorrent stores in each user’s `session/` directory.
- Prevent a few problematic public torrents from degrading performance for everyone on the same server.
- Originated as an operational mitigation for public‑tracker stalls seen in late 2020 (not related to private trackers or user data integrity).

## Scope & Safety
- Only non‑private torrents are processed. Torrents with the BitTorrent `private` flag present are skipped entirely.
- A timestamped backup of every original `.torrent` is written before any change.
- A per‑user log lists which torrents were modified.
- You can disable the cleaner per user with a simple opt‑out file.

## What It Does
- Scans at most 20 `.torrent` files per run for up to 2 users per pass to limit I/O impact.
- For eligible torrents, removes a small list of known problematic public trackers (see list below).
- Appends a short note to the torrent comment indicating a cleanup occurred.

Paths and behavior (per user):
- Session files: `/home/<user>/session/*.torrent`
- Backups: `/home/<user>/session/backups/YYYY-mm-dd_HHMM/<file>.torrent`
- Change log: `/home/<user>/.trackerCleaner.log`
- Opt‑out: create file `/home/<user>/.trackerCleanerDisable`

Scheduling:
- The cleaner runs from root’s crontab and is staggered to avoid I/O spikes. It’s designed as a slow, incremental background task.

## User Control
- Opt‑out completely:
  - `touch /home/<user>/.trackerCleanerDisable`
- Restore a specific torrent from backup:
  - `cp -p /home/<user>/session/backups/<timestamp>/<name>.torrent /home/<user>/session/`
- Inspect what changed:
  - `sed -n '1,200p' /home/<user>/.trackerCleaner.log`

## Editing Trackers Manually (ruTorrent)
- ruTorrent’s “Edit” plugin (Trackers/Comment editor) is present by default and can be user‑enabled via the standard ruTorrent plugin controls.
- ruTorrent permissions allow changing torrent properties, so manual tracker edits from the UI remain available.

## Current Tracker Removal List
The cleaner targets these known problematic public trackers:

```
udp://public.popcorn-tracker.org:6969/announce
http://sub4all.org
udp://tracker.publicbt.com
udp://tracker.ccc.de
udp://tracker.opentrackr.org
http://tracker.tntvillage.scambioetico.org
http://exodus.desync.com
http://tracker.ftfansub.net
http://nyaa.tracker.wf
udp://tracker.istole.it
udp://open.demonii.com
udp://mgtracker.org
```

Note: a blanket removal of all UDP trackers is not enabled. The cleaner uses the explicit list above.

## Operational History (summary)
- 2017: Blog post discussing public‑tracker behavior and performance trade‑offs: https://blog.pulsedmedia.com/2017/10/faster-public-torrents-with-pulsed-media-seedboxes/
- 2020‑11‑06: Cleaner introduced as a mitigation for public tracker stalls; documented in PMSS changelog: https://wiki.pulsedmedia.com/index.php/PM_Software_Stack#06.2F11.2F2020
- 2020‑11‑08: Announcement on avoiding problematic public trackers and related operational notes: https://pulsedmedia.com/clients/index.php/announcements/510/Having-rTorrent-halts-in-BW-Avoid-public-trackers.-plus503-issues-plusDeluge-support-plusTraffic-Limitation-changes-or-Guaranteed-100Mbps-always.html?language=english
- 2020‑11‑12: Follow‑up tuning noted in the same changelog section.
- Knowledge base guidance on stalled torrents (background context): https://pulsedmedia.com/clients/index.php/knowledgebase/88/What-to-do-with-stalled-torrents.html
- Development bounty program (historical context; related upstream issue since closed): https://wiki.pulsedmedia.com/index.php/Pulsed_Media#Development_Bounty_Program
- Repository migration: historical changelog lives on the wiki; current code and changes are tracked on GitHub: https://github.com/MagnaCapax/PMSS/
- The implementation present in this repository is tracked since early 2023 and continues the same policy with safety guards (backups, opt‑out, logging).

## FAQ
- Does this touch private torrents?
  - No. Torrents marked private are skipped. Conservatively, torrents that include the `private` flag (even if set to 0) are treated as private and skipped.
- Can I still edit trackers?
  - Yes. Use ruTorrent’s Edit plugin to change trackers and comments as usual.
- How do I disable the cleaner for my account?
  - Create `/home/<user>/.trackerCleanerDisable`. Remove the file to re‑enable.
- How do I revert a change?
  - Copy the original `.torrent` from the timestamped backup directory back into your `session/` folder.

## Review & Updates
- The tracker list reflects conditions observed when the mitigation was introduced and may need periodic review. If you encounter a tracker that should be removed from or added to the list, please open an issue or contact support with details (tracker URL, symptoms, timing).


## Notes & Caveats
- The “private” detection follows the BitTorrent metadata flag. If a torrent contains the flag, it is skipped to avoid interfering with private tracker policies.
- The cleaner is intentionally conservative and slow to reduce I/O load on shared hosts.
- If you believe a tracker on the list has become reliable again, contact support with details; we can review and adjust the list in a future maintenance window.
