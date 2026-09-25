# ADR 0067: Opt-in per-tenant socket-table privacy

Date: 2026-09-25
Category: security

## Status

Accepted (stage 1 implemented; stage 2 designed, gated on a toolchain decision)

## Context

On a shared host every account shares one network namespace. The kernel's
address-bearing runtime socket state is therefore visible across accounts, and
it carries **remote connection addresses** attributable to the owning account:

- `/proc/net/{tcp,tcp6,udp,udp6,udplite,udplite6,raw,raw6,icmp,icmp6}` are
  world-readable (mode 0444) and expose each socket's `rem_address` and owning
  `uid`. An accepted connection inherits the listening account's uid, so a
  neighbour can join a remote address to the account whose per-user service was
  reached.
- `NETLINK_SOCK_DIAG` (used by `ss`) returns the same rows.
- `ip tcp_metrics` dumps a cache of recently-seen remote addresses.

`hidepid=2` covers `/proc/<pid>` but not `/proc/net/*` (a per-netns file), and
the existing `chmod o-r` of `who`/`w`/`utmp`/`wtmp`/`netstat` (update-step2)
covers the reporting tools and logs, not these kernel sources.

ADR-0025 defines the real cross-tenant privacy boundary as **non-public data: a
customer's connecting IP, and their private file contents/activity**, and names
"another world-readable source of a customer's connecting IP" as a legitimate
finding. This is that source. It was reported privately, fixes-first. Usernames
themselves are public by design (panel URLs, `/etc/passwd`) and are out of scope;
the protected datum is the connecting IP.

A blanket restriction is not acceptable: the customer documentation instructs
tenants to run `ss -tlnp` to find a free port (PMSS allocates service ports
randomly in 2000-38000), and **listening** ports are public. Any control must
preserve listening-socket visibility and hide only the connected rows.

ADR-0011 defers default mandatory access control and requires that any change to
that posture ship as a follow-up ADR with a policy-ownership model, rollback,
observability, and Debian-matrix validation. This ADR keeps the fleet default
unchanged: the feature is **opt-in per host** and does nothing until enabled.

## Options Considered

- **A — Accept and document.** Correct ADR-0025's "already enforced" wording and
  treat the connecting-IP exposure as inherent to the shared namespace. REJECTED
  as the sole response: the exposure is the exact non-public class ADR-0025 names
  as a real boundary, and a low-cost partial control exists.
- **B — Per-tenant network namespaces.** The only primitive that fully scopes
  `/proc/net`. REJECTED: heavy, and it conflicts with ADR-0025's model of tenants
  exposing their own services on open ports.
- **C — AppArmor / SELinux / seccomp / Landlock.** REJECTED: AppArmor attaches to
  executables not accounts and contradicts ADR-0011's MAC-default deferral;
  seccomp cannot inspect netlink payloads so it cannot preserve `ss -tlnp`;
  Landlock has no per-uid `/proc/net` or netlink control.
- **D — Opt-in file-mode + sysctl hardening, plus a UID-scoped kernel filter for
  the netlink channel.** CHOSEN. Closes the sources without namespacing, keeps
  root and the documented `ss -tlnp` workflow, and stays default-OFF.

## Decision

Introduce an **opt-in, default-OFF** per-host feature, enabled by the operator
marker `/etc/seedbox/config/socket-table-privacy.enabled`, applied in two stages.

**Stage 1 (implemented, all Debian releases, no new packages).**
`scripts/lib/update/systemPrep/socketTablePrivacy.php` is the single applier and
the source of the marker path + table list. When the marker is present it sets
the address-bearing `/proc/net` tables to `0440` (root-only) and installs
`net.ipv4.tcp_no_metrics_save = 1` (drop-in `91-pmss-socket-table-privacy.conf`),
flushing the existing metrics cache. With no marker it restores the stock `0444`
mode and removes the drop-in — inert until enabled. The applier runs from
`update-step2.php` after the session/network-binary hardening step; the
`/proc/net` modes are per-netns kernel state that resets on reboot, so
`template.pmss-boot-tuning.sh` reapplies the root-only mode at boot when the
marker is present. This closes the `/proc/net` and `tcp_metrics` channels.

**Stage 2 (designed, not yet implemented).** A BPF-LSM program on `netlink_send`
denies `NETLINK_SOCK_DIAG` dumps of connected socket states for **any uid ≥ 1000**
(covers tenants, their `/etc/subuid` ranges, and `nobody`; keying on a narrower
"tenant range" is bypassable because a tenant can act under a subordinate uid via
rootless-Docker `uidmap`). It **allows** the `CLOSE`/`LISTEN`-state dumps that
`ss -tln`/`-tlnp` send, so the documented workflow keeps working, and leaves root
and system accounts (uid < 1000) unfiltered. The mechanism is prototyped and
load-verified in an isolated namespace on a development host (Debian 13 / kernel
6.12): `ss -tlnp` returns listeners, `ss -tn` returns nothing, a subordinate-uid
process is denied, root is unaffected, clean unload. Stage 2 requires a loader
toolchain that PMSS hosts do not currently carry (`libbpf1` is present but
`bpftool`/`clang` are not), so it is gated on a follow-up decision: add `bpftool`
to the dpkg selection baselines, or ship a minimal loader. It also runs behind
the same marker and reports "unsupported" (fail-open) where the active LSM set,
kernel BTF, or loader are unavailable — Debian 11 (bpftool 5.10, no autoattach)
receives stage 1 only.

**Rollout.** Enable on one pilot host, verify (counts and exit codes only — never
print a customer's remote address), then expand in batches within the fleet
change-operation limit. Flipping the fleet default to ON would be a further ADR
amendment on pilot evidence. Disable = remove the marker; the next update and the
next boot restore stock state.

## Consequences

- **Positive:** closes a non-public cross-tenant leak (connecting IP) named by
  ADR-0025, using the smallest control that preserves the documented `ss -tlnp`
  workflow and touches no customer-facing behaviour. Default-OFF keeps ADR-0011's
  posture intact; the marker gives per-host rollback.
- **Negative:** when enabled, an account also loses the view of its **own**
  established connections via `/proc/net`/`ss -tn` (it retains `ss -tlnp`).
  Stage-1-only hosts still leak the connected rows through `ss` until stage 2
  ships. Stage 1's `/proc/net` `chmod` also affects any non-root system daemon
  (uid < 1000) reading those tables; the pilot censuses such readers before
  enabling.
- **Follow-ups:** the stage-2 toolchain decision (add `bpftool` vs ship a loader)
  and its BPF loader + systemd unit + tests; verification of the CO-RE object
  loading on the Debian 12 / kernel 6.1 fleet at pilot time; a later amendment if
  the default is ever changed.

## References

- ADR-0011 (defer default MAC), ADR-0025 (per-user web hosting; the connecting-IP
  boundary), ADR-0037 (root guard reads `/proc/net`).
- `scripts/lib/update/systemPrep/socketTablePrivacy.php`,
  `scripts/util/update-step2.php` (session/network-binary hardening step),
  `etc/seedbox/config/template.pmss-boot-tuning.sh`,
  `scripts/lib/tests/development/SocketTablePrivacyTest.php`.
