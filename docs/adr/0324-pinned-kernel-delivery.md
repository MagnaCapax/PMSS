# ADR 0324: Pinned kernel delivery through the rolling update

Date: 2026-11-23
Category: architecture

## Status
Proposed

## Context
ADR 0271 lets the running kernel decide whether read caps can safely apply. Its
Decision reserves fleet kernel delivery for a repository change through the
paced rolling update, under a separate ADR. This is that delivery change for
Debian 12. Installing an image and activating it are separate operations;
`update.php` does not reboot a host to activate this pin.

The package has no Depends and an Installed-Size of 634740 KiB, mostly under
`/lib/modules`. Its `/boot` files occupy about 26 MB before initrd generation.
The Debian `linux-image-amd64` metapackage remains selected in the immutable
dpkg baseline. Versioned kernel rows are already filtered during baseline
replay; neither those snapshots nor their sanitiser change here.

## Options Considered
- Add an apt repository: expands the package authority and makes the selected
  build depend on live repository metadata; rejected for this single image.
- Leave fleet delivery manual: does not fulfil ADR 0271's repository rollout.
- Commit one package pin and reuse the verified remote-deb installer: chosen;
  the reviewed SHA-256 fixes the installed bytes without adding a repository.

## Decision
Amend ADR 0271's Decision: qualifying trial images remain a separate host
operation, while fleet installation of the following pin is now a soft-fail
phase-2 step immediately before boot defaults. Activation and reboot stay
outside this change. Do not edit `GRUB_DEFAULT` or remove any kernel package.

| Field | Pin |
| --- | --- |
| Package | `linux-image-7.3.4-zabbly+` |
| Version | `7.3.4-amd64-202611110309-debian12` |
| Release | `7.3.4-zabbly+` |
| URL | `https://pulsedmedia.com/remote/pkg/linux-image-7.3.4-zabbly+_7.3.4-amd64-202611110309-debian12_amd64.deb` |
| SHA-256 | `2716133e4ad7af4245a41245bd2957526b21a3bcc1c37cb365e2ec0c9311c963` |

The pure planner returns one skip reason if the host is not Debian 12, the
package is already installed at this version, the safety hold exists, or any
DKMS module except `wireguard` is registered under `/var/lib/dkms`. WireGuard
is in-tree on 7.x. A failed DKMS inspection also skips installation. Require
at least 250 MiB free on the filesystem holding `/boot` and 1300 MiB on `/`;
log measured and required space, skipping on unavailable measurements too.
Absence of `pmssBootDefaultsEnsureBpfLsm` (#1303) is an explicit fail-closed
refusal. Each skip logs once and lets the update continue.

`/etc/seedbox/config/kernel-pin-hold` records a stopped trial's safety hold.
Its first line is the logged reason; empty or unreadable holds still stop
delivery. This is a safety interlock written by ADR 0271's manual stop path,
not a per-host cgroup setting or a standing policy exception under ADR 0233.
The stop path now writes this file **before** purging the trial package,
regenerating GRUB, checking the previous image is the default, and rebooting
into it. Otherwise the next update would reinstall the stopped image. Clear
the hold only after the stop condition has been investigated and resolved.
The updater only reads this file; it does not automate the stop procedure.

Installation uses `pmssInstallPinnedRemoteDebPackage`: HTTPS, private temporary
download, committed SHA-256 verification before dpkg, existing unattended
environment and profiled `runStep`. Failed installation warns and returns.
After success, warn if the image or initrd is missing, or `grub.cfg` does not
name the pinned release. Log any nonzero `GRUB_DEFAULT` value so the separate
activation operation sees the boot selection. These checks do not prove the
host will boot. Dry runs never publish boot defaults or check absent new files.

### First-boot BPF LSM
ADR 0323's existing caller reads the **running** kernel's active LSM list and
build config. Debian 6.1 already lists `bpf`, so that caller leaves GRUB alone
when 7.3.4 is installed. The target kernel can have BPF-LSM built in but omitted
from `CONFIG_LSM`; without preparation, ADR 0068's default-on socket privacy
stage 2 would remain unloaded on its first boot.

After installation, use the same planner in target-kernel mode with
`/boot/config-7.3.4-zabbly+`. Skip the running-active-list check, require
`CONFIG_BPF_LSM=y`, and append `bpf` to that config's `CONFIG_LSM` order.
Retain exactly the existing explicit `lsm=` and legacy `security=` selection
priority and malformed-input refusals. Publish `/etc/default/grub` through
the existing managed backup/write path; only a successful write permits
`update-grub`. The existing boot-default caller keeps its current behavior.

### Security baseline
Debian sets `kernel.perf_event_paranoid` to 3; upstream defaults to 2. PMSS
writes no line for it: writing 2 while still on Debian 6.1 would lower its 3.
The TIOCSTI baseline is already on main in `a3c3e7cb7`:
`-dev.tty.legacy_tiocsti = 0`, harmless on kernels without that key and ready
for the first newer-kernel boot. No additional sysctl change belongs here.

The BPF verifier has had privilege-escalation vulnerabilities, including
[CVE-2023-2163](https://github.com/google/security-research/security/advisories/GHSA-j87x-j6mh-mv8v),
which allowed kernel-memory access for an attacker with CAP_BPF or
CAP_SYS_ADMIN. This delivery grants neither capability, changes no
unprivileged-BPF policy, and leaves loader integrity and UID guards intact.
No primary or service credentials, secret files, redaction rules, public names,
listeners or web endpoints change. Installer paths remain operator-only;
the downloaded package's maintainer scripts execute as root after verification.
A malicious build therefore threatens the whole host and all tenant isolation.

### Bumping the pin
1. Verify the upstream InRelease signature against the independently pinned
   vendor signing key. Do not obtain a replacement trust key from that download.
2. Verify the Packages index hash against the signed release, then the deb hash
   against its Packages entry. Check package/version/architecture, dependencies,
   module footprint and kernel configuration before accepting the build.
3. Upload those verified bytes to the PMSS package mirror, then commit the new
   package, version, release, URL and SHA-256 constants together for paced delivery.
4. Validate the new image's boot and isolation properties. Purge the previous
   pinned image only **after** the replacement has booted successfully, through
   a separate operator action; the updater never purges kernel images.

## Consequences
- One reviewed image reaches eligible Debian 12 hosts without changing apt
  sources, package baselines, cgroup policy or automatic reboot behavior.
- Keep the existing kernel for recovery. DKMS and low-space hosts need operator
  investigation; a skipped or failed delivery must not stop unrelated updates.
- Accepted residual: one outside builder. The committed hash stops mirror swaps;
  it cannot cover a build compromised at its source or prove hardware suitability.
- On the testing VM, run `KernelPinPlanTest`, `KernelPinStateTest`,
  `KernelPinApplyTest`, `KernelPinBootTest`, `BootDefaultsBpfLsmTest` and
  `BootDefaultsEnsureTest`. Fakes intercept package queries, installation and
  boot-menu activation; none downloads a package or runs real dpkg.
- Before activation, review the package result, image/initrd/menu and logged
  boot selection. Reboot separately, verify the release, v1 memory limits,
  metadata/read-cap behavior and BPF privacy (ADR 0323). Run the read-only
  production probes after delivery. Devbox validation is changed-file PHP lint;
  installation, runtime fixtures and boot validation belong on testing machines.

## References
- [ADR 0271: read caps follow the kernel](0271-read-caps-follow-the-kernel.md)
- [ADR 0323: BPF-LSM boot defaults](0323-bpf-lsm-boot-defaults.md)
- [ADR 0233: no host-local cgroup policy](0233-no-host-local-cgroup-policy.md)
- [ADR 0068: socket-table privacy](0068-opt-in-tenant-socket-table-privacy.md)
