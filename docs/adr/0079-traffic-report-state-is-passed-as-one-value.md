# ADR 0079: Pass traffic report state as one value

Date: 2026-10-01
Category: architecture

## Status

Accepted

## Context

The traffic report builder returned one report array, but JSON and text output
unpacked that array into separate scalar arguments. It also tracked users with
statistics in a second set alongside the full base-user set. Those
representations had to stay synchronized.

## Options Considered

- Keep the argument lists and the second set: no immediate change, but every
  report field addition requires another handoff edit.
- Pass the report array to both output helpers and mark accepted base users in
  the existing set (chosen).

## Decision

`pmssShowTrafficReportBuild()` remains the single owner of totals and summary
counts. JSON and text output receive its complete report. The base-user set
marks whether each user has a valid non-local row. Its key count includes
missing users, and its true-value count gives users with statistics even when
the input list repeats a name.

## Consequences

The CLI text and JSON payloads retain their existing layouts and values. The
output helpers' internal call signatures change, with all in-tree callers
updated together. Existing report snapshots and a text-summary snapshot lock
the rendered contract. No deployed state or migration is involved.
