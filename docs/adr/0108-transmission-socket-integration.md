# ADR 0108: Transmission RPC through a per-user Unix socket

Date: 2026-10-17
Category: architecture
Status: Proposed

## Context

The torrent-client store and account-owned version links exist, but the shipped
catalog has no Transmission artifact with confirmed Unix-socket RPC support.
The per-user lighttpd template already carries the browser-console socket
proxy inside the existing htpasswd area (ADRs 0031 and 0032). Transmission
also writes its own settings on shutdown, so managed values must be applied
while the daemon is stopped.

## Options considered

- Publish a system Transmission row: its RPC socket capability is not recorded.
- Use a TCP RPC listener: it adds a second route beside the per-user proxy.
- Admit only curated static builds that declare `rpcUnixSocket`, and render a
  socket proxy only for a published account selection.

## Decision

The catalog accepts a Transmission entry only when it is `static` and has
`rpcUnixSocket: true`. The host projection carries that capability. The
customer resolver requires it before returning the selected executable.
The shipped catalog contains no Transmission entry, so the integration stays
idle until a curated artifact is added.

The managed `settings.json` uses one spelling of each RPC key. It accepts
existing kebab-case or snake_case files, retains unrelated settings, and writes
only while the account's Transmission daemon is stopped. RPC is served on
`unix:/home/<user>/.lighttpd/transmission.sock` with mode `0700`. The managed
settings keep the RPC whitelist enabled and empty, disable Transmission's own
RPC authentication, remove unused RPC username and password fields, and set
the RPC URL to `/user-<user>/transmission/`. The
per-user lighttpd proxy is the route to that socket and sits before the
`custom.d` include. The account's existing htpasswd rule covers the URL.

The selected launcher runs the foreground daemon under
`flock -n <configDir>/.pmss-launch.lock` for the daemon's lifetime. It checks
the selected executable and probes the socket before starting. The static
build receives `CURL_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt`.

The curated version policy is 4.1.3 as default and 4.0.5 as legacy. Do not
publish 4.0.6 because of its tracker announce race. This policy lives in
catalog curation and tests; it is not a version-number branch in the launcher.

## Consequences

No account receives a Transmission proxy or launch from this catalog today.
Once an artifact is curated, an account selection can activate the existing
settings, proxy, and launcher path without another client-specific code change.
A live canary and the real static artifact are separate build-repository work
for GH #1063 (see also ADRs 0105 and 0107).
