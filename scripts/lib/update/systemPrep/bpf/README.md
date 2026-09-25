# Socket-table privacy BPF-LSM filter

Part of the opt-in per-host socket-table privacy feature — see `docs/adr/0067`.

## What it is

`sockdiag_filter.bpf.c` is a small BPF-LSM program on the `netlink_send` hook. When
the host has enabled the feature (`/etc/seedbox/config/socket-table-privacy.enabled`),
it stops unprivileged accounts (uid ≥ 1000, covering tenants, their `/etc/subuid`
ranges, and `nobody`) from dumping **connected** socket rows via `NETLINK_SOCK_DIAG`
(the interface `ss` uses) — those rows carry a peer's remote address. It still allows
the **listening/unconnected** dumps that `ss -tln` / `ss -tlnp` send (finding a free
port is a documented customer workflow), and leaves root and system accounts (uid <
1000) unfiltered.

This closes the `ss` channel that the file-mode + sysctl restrictions in
`socketTablePrivacy.php` (stage 1) cannot reach.

## Files

- `sockdiag_filter.bpf.c` — source.
- `sockdiag_filter.bpf.o` — prebuilt **CO-RE** object (relocates against each host's
  own kernel BTF; one object serves the whole Debian-12/13 fleet).
- `sockdiag_filter.bpf.o.sha256` — integrity sidecar. The loader refuses to load an
  object whose hash does not match this file.
- `socket-privacy-load.sh` — the fail-open loader (called from update-step2 and the
  boot-tuning unit; BPF state is not reboot-persistent, so it reloads at boot).

## Rebuild

Requires `clang`, `llvm` and `bpftool` (host installs `libbpf1` + `bpftool` only; build
on a workstation or a dev host):

```sh
bpftool btf dump file /sys/kernel/btf/vmlinux format c > vmlinux.h   # needs root
clang -O2 -g -target bpf -D__TARGET_ARCH_x86 -c sockdiag_filter.bpf.c -o sockdiag_filter.bpf.o
llvm-strip -g sockdiag_filter.bpf.o          # drop DWARF, keep .BTF (CO-RE needs it)
rm vmlinux.h
sha256sum sockdiag_filter.bpf.o > sockdiag_filter.bpf.o.sha256
```

Verify the object still loads before committing a rebuild:

```sh
bpftool prog loadall sockdiag_filter.bpf.o /sys/fs/bpf/pmss-test autoattach && rm -rf /sys/fs/bpf/pmss-test
```

## Toolchain

`bpftool` is added to the Debian 12/13 dpkg selection baselines so the loader can run.
Debian 11 ships `bpftool` 5.10 (no `autoattach`), so the feature runs **stage 1 only**
there; the loader fails open and the program is simply not loaded.
