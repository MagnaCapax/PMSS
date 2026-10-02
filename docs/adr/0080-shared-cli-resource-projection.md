# ADR 0080: Share CLI resource projection

Date: 2026-10-02
Category: architecture

## Status

Accepted

## Context

`addUser` and `userConfig` read the same resource option specification, but
maintained separate loops for creation, explicit updates, and defaulted updates.
Help groups separately repeated the resource key order already recorded by the
specification's positional indices.

## Options Considered

- Retain the loops and key lists, requiring coordinated edits for each option.
- Use one resource projection with the existing raw, explicit, and resolved
  value rules, and derive help order from the specification (chosen).

## Decision

`pmssUserConfigCliResources()` is the only resource-value extraction loop.
Creation retains raw strings and omits empty values; explicit updates include
only supplied values and convert integer options; resolved updates include
defaults. Named options retain their existing precedence for each mode. Help
groups use the specification's current order and positional indices.

## Consequences

The two CLI entrypoints retain their arguments, help text, payload values, and
exit behavior. Characterization tests lock the three projections and all five
help groups. No stored payload or credential behavior changes.
