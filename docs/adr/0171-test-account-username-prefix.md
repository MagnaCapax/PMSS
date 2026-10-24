# ADR 0171: Usernames starting with qatst are kept for throwaway test accounts

Date: 2026-10-24
Category: domain

## Status
Accepted

## Context

Throwaway test accounts are created with names of the form `qatst<hex>` and removed again after the test. The test tooling uses that name shape as part of telling a test account from a customer account.

Nothing stopped a customer account from getting such a name. `pmssUsernameCreateValidationError()` refused exact
reserved names (`pmssReservedUsernames()`), not prefixes, so `qatst1` was a valid customer username.

The billing system's signup layer now gives a new order with a `qatst*` name another name, and refuses one on a
username change. A count across all services found no customer account with such a name, so reserving the prefix
changes no existing account.

## Considered options

1. Add `qatst` to `pmssReservedUsernames()`. Rejected: that list holds exact names, so it would block only the bare
   word `qatst` and leave `qatst1a2` open. The list also has another reader (per-user verbose log gating), which
   should not change.
2. Refuse the prefix in `pmssUsernameIsReserved()`. Rejected for the same second reason.
3. Refuse the prefix in the create validation only, with a marker for the test tooling. Chosen.

## Decision

`pmssUsernameCreateValidationError()` refuses a normalized name that starts with `qatst`, with code `reserved` and the
detail "is reserved for test accounts", unless the environment has `PMSS_TEST_ACCOUNT=1`. Every create entrypoint
already goes through that function (`addUser.php`, the provisioning preflight, the system-user create), so this is the
only change. Recreate, rename of an existing account and termination are unchanged.

The marker is an environment variable on a root-run command: only root on the host can set it, and the billing-driven
create path does not set it.

## Consequences

- A create request for a `qatst*` name that did not come from the test tooling fails with the existing `reserved`
  code, which callers already handle like any other reserved name.
- The test tooling must set `PMSS_TEST_ACCOUNT=1` on its create command (it does from 2026-10-24).
- `pmssUsernameIsReserved()` and the exact-name list are unchanged.
