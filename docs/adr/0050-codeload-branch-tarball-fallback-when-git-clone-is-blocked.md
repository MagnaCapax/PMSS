# ADR 0050: update.php falls back to the codeload branch tarball when git clone is blocked

Date: 2026-09-02
Category: infrastructure

## Status
Accepted

## Context
`update.php git/main` fetches source via `git clone --quiet --depth=1 --branch <branch>
<repo>`. In some network conditions this fails with
`fatal: could not read Username for 'https://github.com'` even though the repository is
public and reachable.

Observed mechanism: an intermediary/edge (e.g. GitHub anti-abuse challenging a
source network, or a filtering proxy) can return **HTTP 401 `www-authenticate: Basic
realm="GitHub"`** on the anonymous `git-upload-pack` POST — the git-protocol fetch — so
git falls back to asking for credentials and, with no TTY, aborts. In the same
condition:
- the `info/refs` GET returns 200,
- plain `curl`/HTTPS GETs to the same host return 200,
- the `https://codeload.github.com/<owner>/<repo>/tar.gz/refs/heads/<branch>` tarball
  returns 200.

So the block is specific to the git smart-HTTP `git-upload-pack` path, while
`codeload.github.com` (a separate CDN, and unlike `api.github.com/.../tarball` not
behind the REST rate limit) still serves the same branch tip.

## Decision
`fetchSnapshot()` keeps `git clone` as the PRIMARY fetch for `git/*` specs. When the
clone fails AND the spec has no `pin`, it falls back to the codeload branch tarball
over a plain HTTPS GET, extracted with `tar --strip-components=1` — reusing the
existing `release`-path curl+tar mechanism. `--http1.1` is forced (codeload has a
known intermittent HTTP/2 400 in some CI environments). The extracted tree is still
validated by the existing `ensureSnapshot`/`pmssIsSafeSnapshotPath` checks.

A **pinned** spec (`$spec['pin'] !== ''`) needs git history to check out
`branch@{pin}`; the tarball is history-less, so a clone failure with a pin set stays
fatal (no fallback).

`scripts/lib/tests/development/UpdateCodeloadFallbackTest.php` enforces the wiring:
the codeload fallback is present, uses `--http1.1`, and a pinned spec stays fatal.

## Options Considered
- A - keep git clone only (status quo): the host cannot update while the
  git-upload-pack path is blocked. Rejected.
- B - make the codeload tarball the PRIMARY fetch for git specs: abandons git-clone
  semantics (git/main means git clone) and loses pin support. Rejected.
- C (chosen) - git clone primary + codeload branch-tarball FALLBACK on clone failure,
  no-pin only. Smallest change; the clone-succeeds path is unchanged
  (never-break-old-users); resilient without credentials or new infrastructure.

## Consequences
- Positive: updates self-heal when the git-protocol path is blocked but HTTPS GETs
  still work; no credentials required; the success path is byte-for-byte unchanged.
- Positive: reuses the release-path tar mechanism (DRY); the URL derivation is a pure,
  unit-checkable helper (`pmssCodeloadTarballUrl`).
- Negative: the fallback tarball has no `.git`; deploy does not need it, but a `pin`
  cannot be satisfied via the fallback (kept fatal by design).
- Negative: if codeload is also unreachable, both paths fail and update.php errors as
  it does today (no worse than status quo).
- This is resilience, not a fix for an upstream/edge block itself.

## References
- `scripts/update.php` `fetchSnapshot()`, `pmssFetchBranchTarball()`, `pmssCodeloadTarballUrl()`
- `scripts/lib/tests/development/UpdateCodeloadFallbackTest.php`
