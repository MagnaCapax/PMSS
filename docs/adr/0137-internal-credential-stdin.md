# ADR 0137: Pass internal account credentials through stdin

Date: 2026-10-19
Category: architecture
Status: Accepted

## Context

The shadow updater already accepts credentials through stdin. Other internal
handoffs used command arguments, which are observable in process listings.
The account reset command is also called outside this repository, so its
positional form remains a compatibility contract.

## Options considered

- Keep the argument form for every caller. This retains the observable value
  in internal process listings.
- Add a stdin form and use it for internal calls. Chosen because it keeps the
  positional interface while giving internal calls one consistent transport.
- Replace the positional interface outright. This could break external callers.

## Decision

`changePw.php --password-stdin` accepts one credential line. An empty line
generates a random password as the empty positional value does; EOF without a
line is an error. Internal recreate and add-user calls use it. The qBittorrent child and the Debian 10
htpasswd path also read their credential from stdin. String commands remain
compatible with PHP 7.3. Debian buster's apache2-utils `htpasswd` supports
`-i` for this use.

## Consequences

Existing supplied and generated credential values are unchanged. Internal
process commands contain account names and options, while the credential is
delivered through a pipe. The account reset command's positional form remains
available for external callers.

## Reference

- [Debian buster apache2-utils 2.4.38 `htpasswd` manual](https://manpages.debian.org/buster/apache2-utils/htpasswd.1.en.html): `-i` reads a password from stdin.
