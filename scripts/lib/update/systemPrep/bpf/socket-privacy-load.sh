#!/bin/sh
# Load/unload the opt-in socket-table-privacy BPF-LSM sock_diag filter (docs/adr/0068).
# Pure command orchestration. FAILS OPEN: any unmet precondition -> unload + exit 0
# (never blocks update or boot). Reloaded at boot (BPF state is not reboot-persistent).
set -u
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
OBJ="$DIR/sockdiag_filter.bpf.o"
PIN="${PMSS_SOCKET_PRIVACY_PIN:-/sys/fs/bpf/pmss-socket-privacy}"
MARK="${PMSS_SOCKET_PRIVACY_MARKER:-/etc/seedbox/config/socket-table-privacy.enabled}"
LSM="${PMSS_SECURITY_LSM_PATH:-/sys/kernel/security/lsm}"
BTF="${PMSS_BTF_PATH:-/sys/kernel/btf/vmlinux}"
unload() { rm -rf "$PIN" 2>/dev/null || true; }
[ -f "$MARK" ] && command -v bpftool >/dev/null 2>&1 && [ -r "$BTF" ] \
  && grep -qw bpf "$LSM" 2>/dev/null && [ -f "$OBJ" ] \
  && ( cd "$DIR" && sha256sum -c sockdiag_filter.bpf.o.sha256 ) >/dev/null 2>&1 \
  || { unload; exit 0; }
unload
bpftool prog loadall "$OBJ" "$PIN" autoattach pinmaps "$PIN" >/dev/null 2>&1 || unload
exit 0
