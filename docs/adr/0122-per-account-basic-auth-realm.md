# ADR 0122: Each account's Basic auth realm names the account

Date: 2026-10-19
Category: security

## Status

Accepted

## Context

Every account's panel (`/user-<account>/`) and WebDAV (`/webdav-<account>/`) are served on the
host's one origin and protected by HTTP Basic auth in the account's lighttpd. All accounts used
the same realm string. Browsers scope saved Basic credentials and login prompts by origin and
realm, so each account's area should be its own protection space with its own realm.

## Options Considered

- Keep one realm. Rejected: accounts are separate, and the realm is what browsers use to keep
  their credentials separate.
- Strip or rewrite `Authorization` in nginx per path. Rejected: nginx stays a plain proxy
  (ADR-0009, ADR-0053).
- Put the account name in each realm. Chosen: browsers then keep each account's credentials apart,
  and nothing else changes.

## Decision

Both `auth.require` blocks in `template.lighttpd` use the realm `Password protected area (<account>)`.

## Consequences

- A browser asks for the panel or WebDAV password once after the account's lighttpd config is
  regenerated, because the realm is new. Saved logins for the old realm are offered again once.
- Clients that send credentials without looking at the realm (RPC clients, WebDAV mounts) are not
  affected.
- `require user=<account>`, the htpasswd file and reachability (ADR-0025) are unchanged.

## References

- `etc/seedbox/config/template.lighttpd`
- `scripts/lib/tests/development/WebdavSecurityTest.php` (`testLighttpdAuthRealmNamesTheAccount`)
- ADR-0009, ADR-0025, ADR-0032, ADR-0053
