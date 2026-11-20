# ADR 0096: Intel iGPU firmware and render-node access for all accounts

Date: 2026-10-14
Category: security

## Status

Accepted

## Context

On Debian 12 Intel N100 hosts, i915 can wedge at boot when
`firmware-misc-nonfree` is absent: `tgl_guc_70.bin` and
`adlp_dmc_ver2_16.bin` cannot load. Debian 12's package supplies these files,
and PMSS already enables the `non-free-firmware` repository component.
Debian's udev rule creates `/dev/dri/renderD*` as `root:render 0660`; local
accounts are not members of `render`, so VAAPI remains inaccessible even after
the driver works. The operator decided that every account on an Intel iGPU host
may use hardware transcoding. Most PMSS hosts have no Intel GPU, and main is
applied nightly across the fleet.

## Options Considered

- Add every account to `render`: rejected. Supplementary group changes do not
  reach already-running tmux, systemd-user, or rootless Docker processes. The
  long-lived media sessions addressed by ADR 0094 make that delay material. A
  group containing every account would protect nothing.
- Install firmware unconditionally: rejected. It would add a package and
  rebuild initramfs nightly on hosts that cannot use the firmware.
- Set `i915.enable_guc=0`: rejected. It still needs a reboot and loses HuC.
- Select firmware only when Intel display hardware is present and generate a
  udev rule for `i915|xe` render nodes: selected. It converges on every host
  without changing other GPU permissions.

## Decision

Add `firmware-misc-nonfree` to the Debian 12 dpkg baseline. Extend ADR 0042's
runtime-conditional sanitiser categories with hardware presence: a read-only
PCI sysfs probe retains that package only when an Intel display-class device
exists. Absence removes the selection without emitting a deinstall state.

Generate `/etc/udev/rules.d/70-pmss-render-node.rules` with
`SUBSYSTEM=="drm", KERNEL=="renderD*", DRIVERS=="i915|xe", MODE="0666"`.
Reload udev and trigger DRM change events only when the managed file changes.
The rule targets Intel drivers; virtual and non-Intel GPUs keep Debian's mode.
Firmware activates only at the next boot.

## Consequences

- Every local account, including system users, can open i915/xe render nodes.
  Render nodes exclude modesetting and GEM flink/open by kernel design. i915
  performance counters remain root-only because
  `dev.i915.perf_stream_paranoid` stays at its default `1`; per-client GPU
  fdinfo remains behind hidepid.
- There is no GPU cgroup controller. Accounts share the iGPU; one account can
  saturate it or trigger an engine reset. Kernel i915/xe driver bugs become
  reachable from local accounts, including potential local privilege escalation.
  Keep kernel updates current. The render node exposes GPU ioctls, not account
  credentials; this change does not store or log credentials or expose a new
  network port or web endpoint.
- Intel-GPU hosts also install the other firmware in
  `firmware-misc-nonfree`. A Debian 13 Intel host currently receives the Debian
  12 baseline, but trixie's `firmware-misc-nonfree` lacks i915 firmware;
  `firmware-intel-graphics` is needed there and is deferred until an Intel
  trixie host exists.
- On hosts without Intel display hardware, the package selection is skipped.
  An installed package is not removed by this change. Udev rule generation is
  harmless there because no device matches it.

## References

- PMSS #1006; ADR 0036 (generated managed files); ADR 0042 (runtime-conditional
  baseline sanitising); ADR 0094 (long-lived media-stack tmux sessions).
- [NVD CVE-2023-28410](https://nvd.nist.gov/vuln/detail/CVE-2023-28410):
  historical i915 local privilege escalation via a buffer-bounds flaw.
- [NVD CVE-2010-2962](https://nvd.nist.gov/vuln/detail/CVE-2010-2962):
  historical i915 GEM ioctl local privilege escalation.

## Amendment 2026-10-15: render-group membership

The operator decided that every PMSS account on an Intel iGPU host also belongs
to `render`. Jellyfin and Plex guides and container images check this membership,
and it preserves access if render-node mode changes later. This supersedes the
earlier rejection of group membership; it does not change the Intel-only 0666
udev rule. That rule still covers processes started before a supplementary-group
change, including running tmux, systemd-user, and rootless Docker sessions,
until they restart. Membership grants no access beyond what the 0666 rule
already permits. Hosts without Intel display hardware are unaffected.

## Amendment 2026-11-17: non-free iHD media driver on Intel iGPU hosts

On a Gen9 iGPU (Intel HD Graphics 630, i5-7500T class, Debian 12), Debian's free
`intel-media-va-driver` 23.1.1+dfsg1 decodes but cannot run VAAPI video
processing: `scale_vaapi` fails with "the requested VAProfile is not supported",
so every Jellyfin VAAPI transcode fails. `i965-va-driver` 2.4.1 aborts in its
H.264 encoder. The non-free build `intel-media-va-driver-non-free` (same upstream
version, with the media kernels the free build strips) works. Measured as a
customer account with the system ffmpeg: 1080p to 720p H.264 at 14.3x real
time, 4K HEVC Main10 HDR10 to 1080p at 5.65x, and HDR to SDR tone mapping with
`tonemap_vaapi` at 2.67x. `install-media-stack.sh` runs Jellyfin on the distro
ffmpeg by default, so this driver decides whether hardware transcoding works for
customers at all.

Decision: the baseline sanitiser selects `intel-media-va-driver-non-free` in
place of an `intel-media-va-driver install` entry when an Intel display device is
present and the package is available (the PMSS bookworm and trixie source
templates enable `non-free`). Otherwise the free entry stays. A `hold` entry is
left as written. The packages conflict, and `dselect-upgrade` keeps an installed
package that is still selected for install, so the swap also writes
`intel-media-va-driver deinstall` (the first version without it left the free
build installed on a newly installed Gen9 host); `va-driver-all` accepts either. The baselines
write the entry arch-qualified (`intel-media-va-driver:amd64`), so the match ignores
the `:arch` suffix and both emitted lines keep it (the second version compared the
suffixed name and never fired on a real host). A host whose Intel
display device goes away gets the free entry back on the next update. Testing
the VAAPI path on a Gen9 host means a scale and an encode as a customer account
through the system ffmpeg, not only a decode.

## Amendment 2026-11-18: i915 display power states off on Intel display hosts

Keeping `firmware-misc-nonfree` (above) means i915 loads the display
microcontroller firmware (`kbl_dmc_ver1_04.bin` on Kaby Lake and Coffee Lake)
on the next boot, and the display engine starts entering its DC5/DC6 power
states. On a headless HP ProDesk 400 G4 DM (board 83F3, BIOS 02.15.00,
Debian 12 kernel 6.1), every boot after the firmware landed panicked about
20 seconds in with "Timeout: Not all CPUs entered broadcast exception handler".
`options i915 enable_dc=0` with a rebuilt initramfs stopped it: the host has
stayed up since, the DMC firmware still loads, the DC5/DC6 counters stay at 0,
and VAAPI is unaffected, because render and media engines are separate from the
display power states. The same signature is reported publicly on other HP
G4/G5 minis and an Intel NUC8 (Debian bugs 1050852 and 1036644, drm/intel
issue 9244), and `kbl_dmc_ver1_04.bin` is the newest KBL DMC, so a firmware
update does not fix it. BIOS updates are not a remote option on these boards.

Decision: on every host with an Intel display device, update writes the managed
`/etc/modprobe.d/pmss-i915-dc.conf` with `options i915 enable_dc=0`, then
checks each `/boot/initrd.img-*` with `lsinitramfs`, because i915 loads from the
initrd. If any initrd lacks the file, it runs `update-initramfs -u -k all` once
and checks again, warning about any initrd still missing it. The option applies
from the next boot, so a host gets it before the reboot that would first load
the firmware. The gate is the whole Intel display class, not a board list: a
board missing from a list boot-loops and recovers only with hands on the box,
while the cost on a headless server is a little idle display-engine power.
Rejected: `i915.disable_display=1` (on 6.1 it only stops hotplug polling and
keeps DMC and the DC mask), `mce=` tolerances (they hide the report while the
CPUs stay hung), and blacklisting i915 or `nomodeset` (they remove the render
node this ADR provides).

## Amendment 2026-11-20: per-account render-node deny

A product sold without iGPU access needs an operator-provisioned per-account
policy on a shared host. `gpuEnabled` defaults to true for existing accounts;
`addUser.php` and `userConfig.php` accept `--gpu-enabled=true|false`. Only the
root-owned user config store supplies this policy. There is no customer toggle.

Keep the existing 70- rule and mode 0666. Mode 0660 with render-group membership
would break entitled accounts in rootless Docker: the runtime resets
supplementary groups and the host render GID is not mapped into the container.
Instead, on Intel display hosts generate the managed, mode-0644 file
`/etc/udev/rules.d/74-pmss-render-node-deny.rules`, with one line per disabled,
validated account still present in passwd:

```
SUBSYSTEM=="drm", KERNEL=="renderD*", DRIVERS=="i915|xe", RUN+="/usr/bin/setfacl -m u:<name>:--- $devnode"
```

Named-user ACL entries take precedence over group membership and other-mode
permissions. Skip adding disabled accounts to render, but retain existing
memberships. Debian's `acl` tools are in every baseline (Debian 13 already
selected them; Debian 12 now does too). Remove the deny rule when no disabled
accounts remain.
Creation applies the first stored policy before services start; reconfiguration,
termination and normal updates also converge the rule. Updates retrigger even
unchanged deny rules, wait for udev, and read back each disabled account's numeric
ACL entry on every Intel render node, warning on missing entries and reporting
one account-count summary. Command failures remain visible in the update log.

In systemd v252, the uaccess builtin flushes named-user ACL entries even without
an active seat session. A one-off setfacl would disappear at the next device
event. The late 74- RUN follows 73-seat-late.rules and its uaccess builtin in
udev's ordered RUN list, restoring denies after that flush. A console login on
seat0 also lets logind flush user ACLs outside udev; access then fails open until
the next udev event or update. The update's read-back reports the observed state,
not a guarantee against a later seat change.

Permissions are checked at open: file descriptors already open stay usable
until the process ends. The deny list is visible to local accounts through
getfacl (and the readable rule). This is an access entitlement, not a GPU
resource quota or a replacement for kernel security updates. Historical i915
local privilege-escalation vectors include CVE-2023-28410 and CVE-2010-2962,
referenced above; enabled accounts retain that ioctl attack surface. No primary
or service credentials are introduced, read or logged, and no listening port,
public name or web endpoint is added. Native and rootless-container denial,
re-enablement and device-event behavior require acceptance on real Intel hardware;
unit fixtures alone do not establish enforcement.

Implementation references: systemd v252
[uaccess](https://github.com/systemd/systemd/blob/v252/src/udev/udev-builtin-uaccess.c),
[ACL flushing](https://github.com/systemd/systemd/blob/v252/src/shared/devnode-acl.c),
[ordered RUN execution](https://github.com/systemd/systemd/blob/v252/src/udev/udev-event.c)
and [seat ACL changes](https://github.com/systemd/systemd/blob/v252/src/login/logind-seat.c).
