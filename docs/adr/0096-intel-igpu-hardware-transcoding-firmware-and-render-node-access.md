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
left as written. The packages conflict, so apt removes the free build when it
installs the non-free one; `va-driver-all` accepts either. A host whose Intel
display device goes away gets the free entry back on the next update. Testing
the VAAPI path on a Gen9 host means a scale and an encode as a customer account
through the system ffmpeg, not only a decode.
