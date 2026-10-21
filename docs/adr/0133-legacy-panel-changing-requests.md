# ADR 0133: Require explicit requests for legacy panel changes

Date: 2026-10-19
Category: security
Status: Accepted

## Context

Welcome's restore and Deluge rotation forms posted without the panel's XHR
header. The PMSS copy of Tiny File Manager also accepted changing GET links
and tokenless POSTs. A browser can attach cached Basic credentials and a Lax
panel cookie to a top-level navigation.

## Options considered

- Leave the legacy forms and links in place: a cross-site page could trigger
  account changes.
- Remove the old controls: this would break accounts awaiting Configure delivery.
- Keep the controls and require an explicit request: selected.

## Decision

Welcome's two legacy mutations use the existing POST/XHR guard; their forms
submit through same-origin AJAX. Tiny File Manager keeps a random action token
in its existing session and requires a matching POST token for every changing
handler. Its changing links and AJAX calls send POST; read views remain GET.
The legacy savedata editor also requires the token. AJAX dispatch requires a
string type and strict comparisons; only search and password-hash generation
are read-only exceptions. The contract test derives write sites from PHP
tokens and rejects missing, late, or sibling guards rather than pinning routes.

## Consequences

Normal same-origin controls retain their results. Old bookmarked changing GET
URLs receive 403.
