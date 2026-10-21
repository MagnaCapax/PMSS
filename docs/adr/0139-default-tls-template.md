# ADR 0139: Inherit TLS protocols in the default self-signed site

Date: 2026-10-19
Category: architecture
Status: Accepted

## Context

The default HTTPS site contained a server-level protocol directive that
overrode the global `TLSv1.2 TLSv1.3` setting. Its cipher list also contained
older non-AEAD suites. This template is used when a complete Let's Encrypt
certificate set is unavailable.

## Options considered

- Keep the server override and long cipher list. This leaves the default site
  with different protocol and cipher choices from the global configuration.
- Remove the override and retain only the eight AEAD suites already listed.
  Chosen because the site then inherits the existing global protocol policy
  without introducing a new cipher.
- Duplicate the global protocol setting in the site. This creates another
  value that could drift when the global template changes.

## Decision

The default self-signed site has no `ssl_protocols` directive. Its cipher list
contains only the existing ECDHE-ECDSA, ECDHE-RSA, and DHE-RSA GCM or
CHACHA20-POLY1305 suites. All other site directives remain as before.

## Consequences

The default site inherits TLSv1.2 and TLSv1.3 from the global template.
Hosts with a complete Let's Encrypt set continue to use their separate include.
The template test checks the exact cipher list and inherited protocol policy.
