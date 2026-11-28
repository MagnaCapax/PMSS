# ADR 0260: Support request observation and transport boundaries

Date: 2026-11-10
Category: architecture

## Status

Accepted.

## Context

The account-run support command already separates request orchestration from
configuration, diagnostics and mail delivery. Within those includes, diagnostics
mixed account identity, collection and private snapshot publication; mail mixed
envelope rendering, transport selection, process ownership and SMTP replies.
Collection copied its metadata into a second return representation. SMTP used
separate exchange paths for commands, message data and quit.

## Options Considered

- Keep the mixed modules and simplify individual expressions. This leaves the
  observation and transport ownership boundaries implicit.
- Introduce transport classes and a new diagnostic model. This adds interfaces
  and migration work to a small procedural subsystem.
- Retain the public includes and helpers, separate their existing responsibilities,
  and consume the existing records directly. Chosen.

## Decision

- `diagnostics.php` retains snapshot writing, including its guarded paths,
  exclusive creation, flush and cleanup. `identity.php` owns caller observations
  and the existing account-marker reader. `collect.php` normalizes the message,
  collects the fixed commands and builds text from the returned diagnostic record.
- `mail.php` retains transport selection. `envelope.php` validates and renders the
  existing envelope; `sendmail.php` owns process and pipe cleanup; `smtp.php` owns
  the connection and bounded reply reader. Each direct transport still validates
  its envelope independently of the facade.
- SMTP uses one ordered write/reply traversal for commands, body and quit. Each
  exchange retains its exact wire bytes, accepted status codes and write-error
  context. The greeting precedes that traversal; socket closure remains in `finally`.
- No public helper, option, payload field or execution mode is added or removed.
  Four internal concepts disappear: the parallel diagnostic metadata projection,
  the repeated process-command templates, and the special body and quit exchange
  controllers. The modules name responsibilities already present.

## Consequences

The original includes expose the same helpers; no alternative implementation is
kept. Characterization fixtures cover diagnostic bytes, metadata and command order,
identity precedence, envelope bytes, SMTP reply and write boundaries, and sendmail
cleanup. Existing snapshot fault fixtures keep loading `diagnostics.php` directly.
The display-only home-marker allowlist follows the collection code to its new path,
with the same three permitted markers (ADR 0152).

Security review considered SMTP command/header injection, peer-controlled replies
and SMTP smuggling (Exim CVE-2023-51766 and the Postfix advisory below). Address
guards, CRLF framing, dot stuffing, accepted replies and the 1000-line reply bound
remain unchanged. The fixtures pin these existing bytes rather than introduce a
protocol change. Transport configuration has no account password, service password
or SMTP AUTH setting; this refactor adds no credential handling, bind or public
route. Diagnostic text and delivery error contents retain their existing formatting
and redaction behavior, including matching process command lines. Snapshots remain
under the caller's home in a 0700 directory with exclusive 0600 files; identity
and account-marker guards are preserved. Exposure of a snapshot would disclose
the existing account diagnostic report, without adding a login credential or
an account/root execution capability.

Devbox verification is limited to changed-file PHP syntax and symbol/reference
inspection. No real mail, network probe, dry run or development suite is executed
there; the lane testing machine and CI execute the characterization fixtures.

## Account delivery amendment (2026-11-23, issue #883)

ADR 0016 also applies to the SSH support command: `/scripts` is not traversable
by accounts. The command and its support modules now live in
`etc/skel/bin/pmssSupport/`, delivered before `bin/support` through the existing
user-file sync. Only the runtime functions actually used are copied into local
modules with their original guards and origin comments; internal names avoid
collisions with root's runtime. Public support helpers, diagnostic/envelope/SMTP
bytes and private snapshot modes stay unchanged. Root usage alerts load the same
root-owned installed `/etc/skel` copy, never an account copy. The support config
contract and permission sweep stay unchanged. Delivery coverage checks every file
and runs the copied wrapper with a loopback SMTP stub as the current unprivileged user;
that scenario explicitly skips root runners and must run on an unprivileged QA
worker. The Exim CVE-2023-51766 review above still applies: SMTP framing and reply
guards are retained, no credential or public endpoint is introduced, and exposed
snapshots retain the same diagnostic-data blast radius.

## References

- [Architecture overview](../architecture.md)
- [ADR 0152: Provisioning-store billing identity](0152-billing-identity-comes-only-from-the-provisioning-store.md)
- [Security testing](../security/testing.md)
- [Exim CVE-2023-51766 advisory](https://github.com/Exim/exim/blob/master/doc/doc-txt/cve-2023-51766)
- [Postfix SMTP smuggling analysis](https://www.postfix.org/smtp-smuggling.html)
