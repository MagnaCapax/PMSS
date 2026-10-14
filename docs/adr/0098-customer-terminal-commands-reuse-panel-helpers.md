# ADR 0098: Customer terminal commands reuse panel helpers

Date: 2026-10-14
Category: architecture

## Status

Accepted.

## Context

Account commands in `etc/skel/bin` run as the customer UID. The panel already
has customer-readable parsers for cgroup memory and traffic limits. ADR 0016
keeps the operator tree outside the customer's PHP include boundary.

## Options Considered

- Copy the readers into each command: rejected because this creates a third
  copy of the traffic bonus clamp and cgroup v1/v2 rules.
- Expose the operator stats command: rejected because accounts cannot traverse
  the operator tree.
- Publish a root-written per-account limits file: rejected because the account
  can already read its enforced cgroup values.
- Reuse the panel readers in `~/www`: chosen.

## Decision

Customer PHP terminal commands load only customer-tree helpers in `~/www`,
following ADR 0016. Each parsing rule has one customer-side copy. A command
refuses to run as root before loading account-owned PHP. Missing or incompatible
helpers make only their dependent output section unavailable. PHP commands in
`etc/skel/bin` receive the same PHP lint, PHP 7.3 compatibility scan, and
customer-context fatal scan coverage as `etc/skel/www`.

## Consequences

Account commands share the panel's interpretation of limits and memory
pressure. A partial panel update remains visible as an unavailable section;
the next PMSS update restores the helper. The command needs no root service,
network call, or extra published account data.
