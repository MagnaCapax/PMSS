# ADR 0163: Rootless Docker bridge MTU follows the RootlessKit link

Date: 2026-10-23
Category: architecture

## Status

Proposed

## Context

Each account's rootless Docker runs behind a user-space network stack that RootlessKit starts. Since
ADR 0027, PMSS starts it through `dockerd-rootless.sh` without setting a network driver or MTU. The
script picks slirp4netns, then pasta, then vpnkit, then gvisor-tap-vsock. It gives the link MTU 65520
for every driver except vpnkit (1500). Docker's bridges keep the 1500 default, so containers send
1460-byte TCP segments that the user-space stack handles one by one. The stack opens ordinary host
sockets, so the link MTU never reaches the host network.

Measured on a production host (Debian 12, slirp4netns), container upload went from 26 to 90 MB/s with
one stream and from 32 to 165 MB/s with eight. slirp4netns CPU per MB fell five- to six-fold. On
Debian 10/11/12 test VMs, LAN transfers into a container rose 1.5–4× with every driver PMSS hosts use
today.

## Options Considered

- **Keep 1500.** No change, and the per-segment cost stays.
- **Switch drivers (pasta).** A larger change with different port-binding semantics, and issue #12
  kept slirp4netns.
- **Fill `mtu` and `default-network-opts` in daemon.json, keeping tenant values.** One converge step
  that applies at the next daemon start.
- **Probe the running link MTU first.** It needs a running daemon, and on PMSS's own start path the
  answer is always 65520.

## Decision

`pmssUserRootlessDockerConfigConverge()` takes a `bridge_mtu` policy, and user maintenance passes
`PMSS_ROOTLESS_DOCKER_BRIDGE_MTU` (65520). The helper fills `mtu` and
`default-network-opts.bridge."com.docker.network.driver.mtu"` only when they are missing. It keeps
tenant values and non-object values as they are, and ignores policy values outside 1280–65535.
`etc/skel` ships both keys for new accounts. The daemon is not restarted, and existing networks are
not recreated.

## Consequences

- **Positive:** container throughput through the user-space stack rises with no driver change and
  no host-visible effect. Accounts without a daemon.json get one with the existing cgroupfs exec-opt.
- **Negative:**
  - Networks that already exist, such as compose `_default` and `pmss-media`, keep 1500 until they
    are recreated.
  - A tenant who starts dockerd with a smaller link MTU (`DOCKERD_ROOTLESS_ROOTLESSKIT_MTU`) or with
    vpnkit gets a bridge larger than the link. It still works, because path MTU discovery runs inside
    the namespace, but measured 475 against 770 MB/s at 1500/1500. Such a tenant sets `mtu` themselves
    and PMSS keeps it.
- **Follow-ups:**
  - The Debian 10/11 slirp4netns helper name fix (#1120), so those releases get slirp4netns
    rather than no driver or gvisor-tap-vsock.
  - Optionally, a panel or runbook note on recreating networks.

## References

- Issue #1121; issue #12 (rootless network driver evaluation).
- ADR 0027 (rootless Docker without the per-user systemd manager), ADR 0019 (cgroup v1 pin).
- `dockerd-rootless.sh` (moby `contrib/dockerd-rootless.sh`): driver order and MTU defaults.
- Docker daemon configuration: `mtu`, `default-network-opts`.
