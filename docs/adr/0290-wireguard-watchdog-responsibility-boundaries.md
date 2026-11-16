# ADR 0290: WireGuard watchdog responsibility boundaries

Date: 2026-11-16
Category: architecture

## Status

Accepted under the operator-approved architectural simplification run.

## Context

The WireGuard cron entrypoint combined command capture, kernel-module parsing,
runtime peer collection, configured/runtime comparison, syncconf staging,
reporting and service-state dispatch. Its module-load adapter had one caller;
configuration refusals had two reporting/exit paths with identical message
formatting. Missing-peer selection also maintained a separate accumulator.

## Options Considered

- Retain the combined cron implementation and its independent helper layers.
- Introduce a service framework or configurable watchdog policy: unnecessary
  for this existing fixed flow.
- Keep the cron include and split existing responsibilities into library modules,
  removing redundant state and dispatch paths. Chosen.

## Decision

Keep `checkWireguard.php` as the entrypoint. `wireguard/watchdog.php` owns
configuration preflight and service dispatch; `watchdogPeers.php` owns module
and peer observations plus reconciliation. `watchdogCommands.php` owns bounded
capture, private syncconf staging, restart execution and outcome reporting.
Loading these modules defines helpers without executing maintenance.

Dispatch reads lsmod directly and retains its failed-command short circuit before
parsing. All non-ok configuration states share one reporting/return path; only
missing configuration remains quiet without debug. Missing-peer selection filters
the configured list using the existing running-key lookup and reindexes it,
preserving duplicates, order and key coercion. Reconciliation retains only the
missing count it consumes. No parallel implementation remains.

## Consequences

- Three redundant mechanisms disappear: the single-caller module adapter, the
  second configuration-refusal path and the missing-peer accumulator. Concepts
  delta: -3. Responsibility modules introduce no additional execution policy.
  Runtime nonblank, noncomment lines fall from 188 to 174 (-14); total runtime
  source lines fall from 250 to 248 (-2), including the module headers.
- Cron invocation, exit codes, 30-second capture deadlines, command quoting,
  stdout bytes, per-account messages and configured/runtime key validation stay
  unchanged. The unknown-systemd fallback still probes `wg show` as before.
- `WireguardWatchdogCharacterizationTest` uses exported real functions with
  intercepted commands, configuration reads, time, user logs and filesystem I/O.
  It locks service-state combinations, repair successes/failures, peer reads,
  sync/restart ordering, temporary-file gates and existing throwable behavior.
  The prior complete-write source contract follows the moved staging function.
- Development verification is changed-file PHP syntax and deleted-symbol search.
  Characterization execution, integration tests and service dry runs remain on
  the testing machine/CI; no watchdog or service command runs on the dev box.

## Security Review

The reviewed CVE-2023-35838 record concerns Windows-client routing/firewall
behavior, not this Linux watchdog. Upstream also documents roaming redirection,
handshake CPU pressure and identity disclosure following server-private-key
compromise. This refactor changes none of those protocol policies.

Stripped interface configuration can contain a VPN private key. Its exposure
would compromise that VPN identity; it is separate from primary account login
credentials and does not itself supply an SSH, panel or shell password. The
existing temporary-file creation, complete-write check, mode 0600, refusal
cleanup and exception boundaries remain unchanged. Diagnostics contain existing
status/stage/return-code messages, never the stripped configuration bytes.
No primary or service credentials are generated, reused or stored differently.
No new listener, bind, public name, DNS record, certificate or web endpoint is
introduced; the same local service commands operate on the existing interface.

## Safety expansion: temporary configuration cleanup

The syncconf helper now owns an acquired temporary file through `finally`.
Payload assembly, write, chmod and command exceptions or errors all attempt
removal before propagation. Failed acquisition still authorizes no removal.
Normal command order, stage/rc results, complete-write and 0600 gates remain
unchanged. A false unlink result remains best-effort; a thrown cleanup fault
retains PHP's normal `finally` precedence over an earlier fault.

`WireguardWatchdogCharacterizationTest` extends the original sync exception
expectation to require removal, and injects exceptions and errors at write,
chmod, sync and unlink boundaries. It checks throwable identity, exact command
and filesystem call order, combined faults and unsuccessful removal without
creating files or running service commands. Changed-file `php -l` checks run
on the devbox; fixture execution and service dry runs stay on testing machines.

The security review above and its upstream sources were rechecked for this
expansion. Temporary payloads still hold only VPN service configuration, never
primary account passwords. Cleanup adds no payload logging or exposure path;
existing key separation, file modes, bind policy and endpoints are preserved.
Persistent unlink failures can still leave a private temporary file behind.

## References

- [Peer-state boundaries](0268-wireguard-peer-state-responsibility-boundaries.md)
- [Operational safety](../security/operational-safety.md)
- [Security testing](../security/testing.md)
- [WireGuard known limitations](https://www.wireguard.com/known-limitations/)
- [CVE-2023-35838 record](https://github.com/CVEProject/cvelistV5/blob/main/cves/2023/35xxx/CVE-2023-35838.json)
