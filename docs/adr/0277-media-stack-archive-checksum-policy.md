# ADR 0277: Media-stack archive checksum policy

Date: 2026-11-12
Category: security

## Status

Accepted

## Context

Servarr API endpoints use generic URL basenames, so checksum lookups for
`latest` or `updatefile` cannot bind to versioned filename pins. Missing pins
must remain compatible with interactive installs of new upstream versions,
while unattended installs need an explicit way to require verification.
ASP.NET already requires the SHA512 published in its release metadata.

## Options Considered

- Require all SHA256 pins by default: stops existing installs on upstream releases.
- Probe redirects before downloading: adds a request and can resolve a different
  release from the subsequent download.
- Capture headers during download, summarize missing verification, and offer
  opt-in strictness: preserves the default installation path and binds IDs to
  the downloaded response.

## Decision

For generic URL basenames, capture wget server responses or curl response
headers into a private temporary file. Prefer the final response's filename,
then the last redirect's basename; accept only a simple archive identifier.
Otherwise retain the original URL basename. Remove the header file before
verification or reporting a download failure. Named archive URLs keep their IDs.

Keep every known SHA256 mismatch fatal. Collect missing verification in one
stderr summary at exit. Add `--strict-checksums`, default off, which requires
a SHA256 pin or the existing mandatory upstream SHA512 check before extraction.
An upstream SHA512 never excuses a SHA256 mismatch. Missing archives outside
dry runs fail rather than being treated as unverified successes.

## Consequences

Versioned Servarr pins can now bind. Unpinned versions still proceed by default,
with their integrity limitation visible at exit. Strict runs can fail after
earlier applications have been installed; there is no transaction or rollback.
Dry runs retain endpoint-only behavior. Git and pip are outside this archive
policy. Automatically trusting upstream checksums for Servarr or Jellyfin
remains a separate decision shared by issues #876 and #923.

Hermetic fixtures cover header selection, fallback, temporary-file cleanup,
both clients' return codes, missing pins and files, strictness, summary exit
status, and both hash checks. Execution belongs on the testing machine.

## References

- Issue #923 (generic Servarr checksum IDs).
- [curl response headers](https://curl.se/docs/manpage.html#-D).
- [wget server responses](https://www.gnu.org/software/wget/manual/wget.html).
- [Installer self-update policy](0048-media-stack-installer-self-update-is-opt-in-not-tty-gated.md).
