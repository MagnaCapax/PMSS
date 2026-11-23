# ADR 0336: Optional TOTP second factor for the panel session login

Date: 2026-11-23
Category: security

## Status
Accepted as a design. Nothing is implemented by this ADR. Implementation is tracked
separately and may not start until the gate in "Implementation gate" is met.
Builds on ADR 0053 (panel session-cookie login); amends it only for an account that
has enrolled, as stated under Decision 1. Refs #1007, #963.

## Context
One credential guards a whole account: the panel, the torrent clients, the app settings,
the files, and (it is the same account password) SSH/SFTP, FTP and WebDAV, as the issue records. A reused or
leaked credential is the usual way an account is taken over. A time-based one-time code
(TOTP, RFC 6238) from the customer's own authenticator app stops that for the web panel.

Facts at the time of writing, each read from the source:
- ADR 0053 keeps "session cookie OR Basic". `scripts/lib/lighttpd/panelSessionGate.lua`
  hands any request that carries an `Authorization` header straight to Basic
  (`log_event(user, "basic", "delegated")`, line 171), so for an enrolled account Basic
  would skip the code entirely.
- The gate writes the session file with `io.open(path, "w")` (line 96), a truncating
  open, and falls back to Basic when it cannot read the file. The PHP writer
  (`etc/skel/www/panelSessionStore.php`) is the one that is expected to write atomically.
- `/webdav-<user>/` is its own Basic-only zone (`etc/seedbox/config/template.lighttpd`), and
  `etc/seedbox/config/template.sshd_config` has `PubkeyAuthentication yes` and the
  `PasswordAuthentication` line commented out (the OpenSSH default is yes). FTP is served by
  proftpd (`etc/seedbox/config/template.proftpd`); its login mechanism is not decided here.
- The session store counts failed logins (`pmssPanelSessionFailedIncrement`), and
  `panelSessionAuth.php` returns the count, but nothing reads it to slow or refuse a
  login, so wrong-code limiting has no existing mechanism to extend.
- The panel's non-browser clients (ruTorrent's `plugins/rpc/rpc.php` endpoint, which
  automation tools such as the *arr apps call) authenticate with Basic against the panel
  prefix.
- Security doctrine (AGENTS.md): primary credentials are stored only as strong hashes; a
  service that needs a plaintext-recoverable secret gets its own random one; passwords are
  never logged.

## Options Considered
- Panel-only TOTP, Basic still accepted: rejected. A badge that a leaked password walks
  around gives false confidence and is worse than no feature.
- TOTP plus automatic closing of SSH/FTP/WebDAV password logins on enrollment: rejected as
  one switch. It bundles three services into a panel ADR, and closing SSH before a key is
  installed locks the customer out of their only shell.
- WebAuthn/FIDO2 for the panel: not chosen now. It needs a browser-side relying-party flow
  in customer PHP that this tree has no precedent for. Hardware keys already work for SSH
  with stock OpenSSH, so they belong to the lockdown switch below, not to this factor.
- Email or SMS reset of a lost device: rejected, see Decision 5.
- One per-account second factor that every door consults: not chosen. It is the right
  shape for the lockdown switch, not for the first release.

## Decision
1. **Basic does not bypass the code for an enrolled account.** For an account that has
   enrolled, the `/user-<user>/` panel zone accepts only a session minted after password
   plus code. The gate stops delegating an `Authorization` header to Basic for that account
   and fails closed: if the gate cannot read the session file, the request is refused, not
   passed to Basic. This reverses ADR 0053's availability fallback for enrolled accounts
   only; a gate fault locks that account out of the panel until it is fixed, and the
   enrollment page says so. A non-enrolled account keeps ADR 0053 behaviour exactly.
   - **Automation path.** Non-browser clients keep working through one narrow exception: an
     enrolled account may create a separate random automation credential, shown once, stored
     only as a bcrypt hash, revocable, accepted by Basic on exactly
     `/user-<user>/rutorrent/plugins/rpc/rpc.php` and nowhere else on the panel zone. It is
     never the account password. An account that creates none loses those clients on
     enrollment, and the enrollment page says so before it takes effect.
   - **Agent web login (ADR 0151).** The temporary support login is a Basic credential on the
     panel zone, so it is refused for an enrolled account. Support checks such an account from
     the host side, or with the customer present, not by a panel login.
2. **Side doors are a separate switch, decided separately.** Enrolling in the panel second
   factor changes nothing for SSH/SFTP, FTP or WebDAV. A later ADR owns an independent
   account-lockdown switch (credential login off for SSH/SFTP, FTP and WebDAV, key-only
   SSH, FIDO2 keys supported by OpenSSH); it does not need TOTP, the session cookie or
   #963. This ADR records two constraints on it: it may not turn password SSH off for an
   account with no authorized key installed, and it needs its own restore path.
   Until a door is closed the enrollment page lists, in plain words, each door that still
   accepts the password. The feature is named "panel second factor"; no text says the
   account is protected as a whole while a door is open.
3. **Enrollment and recovery.** Enrollment happens inside the panel and requires the
   account's `panelSessionLogin` to be on. The page shows a QR code and the manual secret;
   enrollment takes effect only after the customer enters one valid code. TOTP uses
   SHA-1, 6 digits, a 30-second step and a 160-bit random secret, for authenticator-app
   compatibility. The secret lives with the per-user session data under `~/.lighttpd/`,
   mode 0600, readable only by the account, never in a world-readable path and never in a
   log. It is a separate random value, not derived from the password. Ten one-time
   recovery codes of at least 50 bits each are issued at enrollment and stored only as
   hashes. A time step is accepted once (RFC 6238 section 5.2): a replayed code is
   refused. The window is the current step plus or minus one. Disabling the factor needs a
   current code or a recovery code.
4. **Off by default, per account.** The state lives where `panelSessionLogin` lives and is
   written the same way. An account that never enrolls renders byte-identical lighttpd
   config and runs byte-identical gate behaviour.
5. **Lockout path.** An enrolled customer who loses the device and the recovery codes is
   reset through support, which removes the enrollment from the host side. The reset must
   rest on a credential other than the one that may have leaked, for example control of the
   billing account's login or its email address, never on knowledge of the panel password.
   The customer is told at the billing email when a reset is done. There is no
   self-service reset by email.
6. **Rate limiting.** Wrong codes are limited at two levels. Per source address: after 5
   wrong codes, further attempts are refused for 60 seconds, doubling per further failure up
   to one hour. Per account, as a ceiling that does not depend on the source: at most 30
   wrong-code attempts per hour across all sources, after which code entry for the account
   is refused for the rest of that hour. With a plus or minus one step window 3 of 10^6
   codes are accepted, so about 333,000 guesses are expected; at 30 an hour that is about
   460 days (computed, not measured). A bare per-account counter would let anyone who knows
   the password keep the owner locked out of the code step, so recovery codes are counted
   separately: they have enough entropy that the per-source delay is sufficient for them,
   and the account ceiling does not apply to them. An owner throttled by an attacker uses a
   recovery code, and sessions that are already signed in are not affected.

## Consequences
- Positive: a leaked or reused password no longer opens the panel of an enrolled account;
  the design is honest about the other doors instead of implying whole-account protection.
- Positive: the lockdown switch becomes a small independent change that protects the
  strongest door, the shell, without waiting for TOTP.
- Negative: enrolled accounts lose Basic on the panel zone, so automation clients need the
  automation credential, and a gate fault locks out an enrolled account instead of falling
  back to Basic. Both are stated at enrollment.
- Negative: the enrolled factor adds pre-auth PHP (login and code handling) that runs as the
  account, new surface that needs the customer-tree review checklist (ADR 0017).
- Negative: support carries a reset workflow with an identity check that does not rest on
  the leaked password.
- Customer PHP stays inside the customer tree; no `/scripts` require (ADR 0016). No new
  public name, port or certificate (ADR 0150): the code step is served on the existing
  panel path.

## Implementation gate
Implementation is tracked in its own issue and starts only when all of these hold:
- #963 is done: the session-cookie login is shown working against a real per-user lighttpd,
  including that lighttpd accepts the gate-set `REMOTE_USER` under the panel zone's
  `"method" => "basic"` (or the zone moves to a method that does), and the package that
  supplies `mod_magnet` installs on the supported releases (#854).
- A test that runs real lighttpd with the generated per-user config and sends real requests
  exists and passes (not skips): cookie accepted, Basic refused for an enrolled account, gate
  fault refused, automation path accepted only on its one path, `/webdav-<user>/` untouched.
  Existing real-lighttpd tests (the agent login test and the config syntax test) are the
  starting point; neither exercises `mod_magnet` or the session gate yet.
- The gate's session write is atomic (temporary file and rename, as the PHP writer does), so
  a parallel asset request cannot read a half-written file and be refused by the fail-closed
  rule.
- The sizing of the wrong-code limits in Decision 6 is checked against the real request path.

## Security Review
Attack surface: session theft, login CSRF (the existing token rules apply to the code step),
code guessing, replay, secret exposure to an account-level compromise, header smuggling and
authentication bypass classes in the HTTP server (see the CVE notes in ADR 0053), and
social-engineering of the support reset.
- The TOTP secret must be readable to verify, so it is recoverable by anyone who gets a
  shell or file read as the account. That is why the lockdown switch matters: the factor
  protects against password theft and reuse, not against an attacker already inside the
  account.
- The code step must not log the code, the secret or the recovery codes, and must not echo
  whether the password or the code was the wrong part beyond what the login already does.
- Recovery codes are hashed at rest, single use, and shown once.
- The automation credential is a separate random value with a hash at rest; it is scoped to
  one path, so its exposure does not open the panel UI.
- Blast radius of a mistake is one account's panel access; the off-by-default rule keeps
  every other account byte-identical.

## References
- GH #1007: this decision. GH #963: gate path match and cookie handoff. GH #854: package
  supplying `mod_magnet`. GH #1229: the panel's Basic realm collides with an app's own auth
  on the same path (same zone).
- ADR 0053 (panel session-cookie login), ADR 0151 (agent web login), ADR 0122 (per-account
  Basic realm), ADR 0016 and 0017 (customer tree), ADR 0150 (no forced public identifiers).
- RFC 6238 (TOTP), RFC 4226 (HOTP), OWASP Authentication and Session Management cheat sheets.
