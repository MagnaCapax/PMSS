# ADR 0072: User transfer hands over rootless Docker containers

Date: 2026-09-26
Category: architecture
Status: Accepted

## Context

The final volatile passes of `userTransfer` copy the account's rootless Docker
data while containers may still be writing to it. The source host's
`checkRootlessDocker` watchdog restarts an enabled daemon after it stops, so
stopping the daemon does not provide a stable transfer window. ADR 0027 and
ADR 0066 document the supported per-user daemon lifecycle.

## Decision

After the existing final passes, the transfer probes the source account's
rootless Docker socket and stops up to 500 running containers. It records that
same set of IDs, then makes one more final pass over the quiesced data. After
post-setup, it starts the rootless daemon and the recorded containers on the
target if the final pass returned 0, 23, or 24. If that pass fails, it restarts
the set on the source and does not start target containers. After a successful
transfer, source containers remain stopped so services do not run on both hosts.

Container IDs and captured output are bounded; IDs must be exact lowercase
64-character hexadecimal strings. Docker commands run as the account on each
host, never as root. Logs report container counts without IDs.

## Consequences

Accounts without a reachable rootless Docker socket continue through the
existing transfer flow. An account with running containers gets one additional
volatile pass and a bounded wait for the target daemon to become ready. A
failed extra pass returns failure after attempting to restore source service.
