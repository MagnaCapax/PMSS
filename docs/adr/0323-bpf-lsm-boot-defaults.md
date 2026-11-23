# ADR 0323: BPF-LSM activation through boot defaults

Date: 2026-11-23
Category: security
Status: Proposed

## Context
ADR 0068's socket-table filter requires `bpf` in the active LSM list. A kernel
can build BPF-LSM support without selecting it in `CONFIG_LSM`, leaving stage 2
inert while stage 1 works (#1303). Active names differ from boot-selection
names: integrity may appear as ima/evm, and capability is always included.

## Options Considered
- Copy the active list into `lsm=`: includes runtime names unsuitable for boot selection.
- Set `lsm=bpf`: drops the kernel's other selectable security modules.
- Extend the running kernel's build defaults and preserve explicit selections:
  retains existing security modules and uses the established GRUB convergence path.

## Decision
The phase-2 boot-default helper prepares an `lsm=` parameter only when the
active list is readable and lacks `bpf`, and `/boot/config-<running-release>`
declares `CONFIG_BPF_LSM=y` and a nonempty literal `CONFIG_LSM` list.

Use build-default names, retaining explicit `lsm=` entries ahead of those
defaults so a selected major module keeps priority. Prefer the last parameter
within a command line and `GRUB_CMDLINE_LINUX_DEFAULT` over `GRUB_CMDLINE_LINUX`.
When no explicit list exists, preserve a legacy `security=` selection ahead of
the defaults. Deduplicate the resulting names and include `bpf`. Consolidate
literal lists into one parameter in `GRUB_CMDLINE_LINUX_DEFAULT`, preserving
other arguments. Leave shell expressions, malformed lists and unreadable
observations alone. No new host configuration switch is introduced.

GRUB setting helpers live in `bootDefaultsGrub.php` behind the existing
`bootDefaults.php` include. The controller retains managed backups and the
successful-write gate before `update-grub`. The pin takes effect on reboot;
the BPF loader retains all its existing prerequisites and fail-open behavior.

## Consequences
- Hosts with BPF-LSM support can activate the existing privacy filter on their
  next boot. Kernels without built-in support and hosts already running BPF-LSM
  receive no LSM boot-option changes.
- Existing explicit and build-default modules remain selected. Kernel changes
  still require boot validation; parsing a build config cannot prove boot success.
- Testing-machine coverage is `BootDefaultsBpfLsmTest` and `BootDefaultsEnsureTest`.
  Before fleet rollout, boot a test host and verify that `bpf` appears, previously
  active security modules remain active, connected socket dumps are denied to
  an unprivileged fixture account, and listener discovery still works. Inspect
  counts and exit statuses without recording customer addresses.
- Rollback: restore the managed GRUB backup, regenerate GRUB and reboot. A later
  PMSS update will prepare the pin again if the prerequisites still hold.
- Loader policy-state reporting remains separate (#1149).

## Security Review
The protected information is other accounts' remote connection addresses.
No credentials, secret files, listeners or public endpoints are introduced.
Logging contains only the operator GRUB path and reboot intent. The loader's
object-integrity checks and UID policy are unchanged.

The eBPF verifier has had privilege-escalation flaws, including CVE-2023-2163;
the original advisory describes kernel-memory access and container escape for
attackers with CAP_BPF or CAP_SYS_ADMIN. This change grants neither capability
and does not change unprivileged-BPF policy. Kernel patching remains required.

## References
- [ADR 0068](0068-opt-in-tenant-socket-table-privacy.md)
- [Kernel LSM selection](https://docs.kernel.org/admin-guide/LSM/index.html)
- [Privileged BPF-LSM loading](https://docs.kernel.org/bpf/prog_lsm.html)
- [CVE-2023-2163 original advisory](https://github.com/google/security-research/security/advisories/GHSA-j87x-j6mh-mv8v)
