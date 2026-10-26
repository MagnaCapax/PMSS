# ADR 0184: qBittorrent Enhanced Edition entry with its warning in the version

Date: 2026-10-25
Category: domain

## Status

Accepted.

## Context

qBittorrent Enhanced Edition is a community build of qBittorrent. Release
5.2.4.10 is based on qBittorrent 5.2.4 and links libtorrent 1.2.20. Its own
notes ask users not to run it on private trackers unless the tracker allows it:
it identifies itself as `qBittorrent Enhanced/5.2.4.10` with a peer ID that
differs from stock 5.2.4, so trackers can tell it apart.

The release is published only as a zip. The PMSS store installs a single
pinned binary for `kind: static` entries, so the binary is re-hosted on
pulsedmedia.com/remote/pkg, next to the upstream zip, the upstream source
tarballs and their checksums.

Panels that have not yet received current panel files (#1162) show a version
exactly as published when they do not recognise it, and they do not show
catalog notes. A warning kept only in the note would not reach them.

## Decision

- The catalog entry's version is `5.2.4.10-ee-lt1.2-not-for-private-trackers`.
  Every panel shows that text at least; current panels label it
  "5.2.4.10 Enhanced Edition (libtorrent 1.2), not for private trackers" and
  show the note.
- `pmssQbittorrentBuildNumber` reads the entry as release 5.2.4, so the
  WebUI section, the restore below 5.3 (ADR 0182) and the upload-limit key
  treat it as the 5.2.4 build it is. A store build is never probed.
- The major-downgrade guard (ADR 0120) parses the label, so a switch from it to
  a 4.x build is refused like any 5.x to 4.x switch.
- The binary is fetched from pulsedmedia.com and pinned by sha256
  (4b291578e28663c7d1c88bf41993a832c0eb68b3f9bd345716524c3f37d0565a).
  Published files are never replaced; a new build gets a new name.

## Consequences

- Panels with older panel files keep the older downgrade guard, which does not
  parse this label. For them a switch from this build to 4.x is not refused
  until their panel files are refreshed (#1162).
- The version id is a store directory name and stays as published.
- Further Enhanced Edition releases get their own entries with the same suffix.
