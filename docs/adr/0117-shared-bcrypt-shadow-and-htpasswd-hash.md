# ADR 0117: Share one bcrypt hash between shadow and htpasswd

Date: 2026-10-18
Category: security
Status: Accepted

## Context

`changePw.php` generated separate shadow and htpasswd hashes. On Debian 11+, PAM commonly generated `$y$` in shadow, and unsuspend copied that hash into htpasswd. Measured on stock Debian and a production Debian 12.15 host, `$2y$` is the only format accepted by all four readers: pam_unix, lighttpd, the PHP panel session check, and rclone rcd. Debian 10 pam_unix rejects `$2y$`.

## Options Considered

- Keep separate hashes and change unsuspend's copy. This preserves drift between the two stores.
- Change readers to accept yescrypt. The PHP and rclone readers do not share one usable yescrypt implementation.
- Generate one bcrypt hash and write it to both stores where PAM supports it.

## Decision

On Debian major version 11 or newer, `changePw.php` generates one `$2y$` hash with `password_hash(PASSWORD_BCRYPT)`, feeds `username:hash` to `chpasswd -e` on stdin, and writes that same hash to the account-owned htpasswd file. Debian 10 and unreadable or nonnumeric versions retain `passwd` and `htpasswd -B`; `passwd` now receives plaintext only on stdin. Bcrypt uses at most the first 72 input bytes, the same limit already present in `htpasswd -B`.

## Consequences

- A successful reset on Debian 11+ leaves both stores with the same portable hash, and unsuspend can copy it without changing its behavior.
- A failed shadow update aborts before the htpasswd write. A later htpasswd failure is still reported as a partial credential sync.
- Debian 10 retains its established hashes and reader limitations.
- Shadow remains root protected; htpasswd retains the account-owned managed writer and mode. Neither plaintext nor hash is passed on the shadow updater command line. The per-user htpasswd is readable by the account and services using it, so a copied hash exposes offline guessing potential if that account is compromised; reusing the existing file does not widen access.
- Internal callers pass the account credential to changePw, qBittorrent, and htpasswd on stdin.

## References

- `docs/contracts.md`: changePw command contract.
- `docs/adr/0016-customer-php-tree-separation-from-operator-scripts.md`: panel helper placement.
- [PHP password_hash manual](https://www.php.net/manual/en/function.password-hash.php): `$2y$` and the 72-byte limit.
- [Debian shadow security tracker](https://security-tracker.debian.org/tracker/source-package/shadow): reviewed for the command's attack surface; the listed `chfn`/`chsh` field injection history does not change this stdin-only `chpasswd` use.
