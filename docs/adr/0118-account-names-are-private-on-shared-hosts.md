# ADR 0118: Account names are private on shared hosts

Date: 2026-10-19
Category: domain

## Status

Accepted

## Context

ADR 0025 and ADR 0068 treated account names as public by default. The current
product principle gives each customer the choice to share their account name.

## Decision

On shared hosts, an account's name is not shown to other accounts by PMSS
surfaces unless the customer chooses to share it. This supersedes in part ADR
0025's and ADR 0068's earlier statements about account names.

Directory modes and per-session account views, opt-in by marker, are delivered
by later changes.

## Consequences

Existing customer choices to publish a name remain valid. Later changes apply
the principle while preserving supported account workflows.
