# ADR 0208: ~/.config modes are strip-only

Date: 2026-10-29
Category: security

## Status

Accepted

## Context

Every permission pass set everything account-owned under `~/.config` to exactly 0770, files
included (#1217). The rule came in with the 2023 initial import and no recorded reason. The
pass runs often (update, boot sweep, rebuild, transfer, new account), so a customer's 0700
directories and 0600 secret files went back to 0770 each time. PMSS's own writers disagree
with it too:
- slskd loads its owner settings file only at mode 0600, so the pass silently disabled those
  settings.
- ADR 0142 keeps the Docker configs at 0600.
- Several writers set 0700/0600 under `~/.config/pmss`.

No process other than the account itself needs group or other access there: the services
run as the account, and PMSS adds no members to an account's private group.

## Options Considered

- **Stop touching `~/.config`.** This also drops the useful part: removing world access.
- **Directories only.** Still raises 0700 directories, and leaves world-readable files.
- **Strip-only.** Clear the bits 0770 lacks (other rwx, setuid, setgid) and never add one.

## Decision

`~/.config` is strip-only. The recursive pass selects entries with `-perm /6007` and runs
`chmod u-s,g-s,o-rwx`, so 0600 and 0700 stay, 0644 becomes 0640 and 0777 becomes 0770. The
home-wide 0750 directory walk prunes `~/.config`, as it already prunes `~/.local`.
Single-link and owner checks are unchanged. Other recursive items keep their exact modes.

## Consequences

- Owner-only modes in `~/.config` survive every pass from now on.
- Entries already reset to 0770 stay that way until their owner or app changes them; there
  is no bulk repair.
- A file the account left without owner access is no longer fixed as a side effect.
