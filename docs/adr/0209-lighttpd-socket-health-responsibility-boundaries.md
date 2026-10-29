# ADR 0209: Lighttpd socket-health responsibility boundaries

Date: 2026-10-29
Category: architecture

## Status

Accepted under the operator-approved architectural simplification run.

## Context

The lighttpd watchdog combined connection probing, listener parsing, post-restart
verification and consecutive-failure persistence in one include. Connections and
listener verification independently implemented the same retry cadence. The cron
script also tracked a cycle-failure flag alongside its socket restart decision.
Those paths must retain their diagnostic bytes and conservative restart gates.

## Options Considered

- Keep the combined include and duplicate retry controllers.
- Separate socket responsibilities behind the existing include, share retry
  timing and return the cron decision directly (chosen).
- Share transport with rTorrent SCGI, whose request/response protocol and queue
  validation have different contracts.

## Decision

Keep `watchdogSocketProbe.php` as the existing include and connection API.
Separate its responsibilities into adjacent operator-side modules:

- `watchdogSocketRetry.php`: overrideable constants and one observation/sleep loop.
- `watchdogSocketListeners.php`: listener parsing, snapshots, coverage policy and
  post-restart verification.
- `watchdogSocketState.php`: validated marker paths, complete writes and clearing.
- `watchdogSocketHealth.php`: ordered per-worker evidence, cron diagnostics and
  the socket restart decision.

The retry controller returns the first successful observation or final failure
with the actual attempt count. Consumers retain their own success criteria:
connections require a successful stream and clean errno/error text, while
verification requires a successful snapshot and enough distinct listeners.
Neither success nor the last failed attempt sleeps.

The cron-facing helper returns after the first unresolved failed socket. Missing
php-cgi and persisted failures at the existing threshold request a restart;
transient failures defer it. A refused stale index still requires live listener
coverage. Existing marker validation, write flags, defaults and diagnostics are
preserved, including the original two clear calls on the stale-index path.

## Consequences

The concept ledger removes one independently maintained retry controller and
one transient cycle-failure flag, adding one module-loading set: net minus one
mechanism. No second implementation or compatibility dispatch is retained.
All existing helper names, signatures, constants and include paths remain valid.
The additional modules are delivered with the operator scripts tree; customer
PHP gains no dependency on that tree.

`LighttpdSocketHealthCharacterizationTest` locks cron result/message/call traces.
`LighttpdSocketRetryCharacterizationTest` locks both consumers' cadence, callback
normalization, final snapshot classification, native stream closure and throwable
propagation. Existing socket tests retain malformed-path, parser, listener-count,
marker-type, metadata and failed-persistence coverage. Tests run on the testing
machine; devbox verification is limited to syntax checks of changed PHP files.

## References

- `docs/refactoring.md`: behavior-preservation and focused-module rules.
- `docs/contracts.md`: lighttpd watchdog contracts.
