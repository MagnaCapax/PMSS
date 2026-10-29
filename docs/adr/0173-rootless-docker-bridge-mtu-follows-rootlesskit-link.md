# ADR 0173: Rootless Docker bridge MTU follows the RootlessKit link

Date: 2026-10-23
Category: architecture

## Status

Accepted

## Context

Each account's rootless Docker runs behind a user-space network stack that RootlessKit starts. Since
ADR 0027, PMSS starts it through `dockerd-rootless.sh` without setting a network driver or MTU. The
script (`docker-ce-rootless-extras`) picks the driver and the link MTU:
- 26.1.4, the last Debian 10 build: slirp4netns (v0.4.0 or later) at 65520, else vpnkit at 1500.
- 29.x (measured 29.8.0 on Debian 11 and 29.9.1 on Debian 12): slirp4netns, then pasta, then vpnkit,
  then gvisor-tap-vsock; 65520 for slirp4netns, pasta and gvisor-tap-vsock, 1500 for vpnkit.

Docker's bridges keep the 1500 default, so containers send 1460-byte TCP segments that the user-space
stack handles one by one. The stack opens ordinary host sockets, so the link MTU never reaches the
host network.

Measurements reported in #1121: on a production host (Debian 12, slirp4netns), container upload went
from 26 to 90 MB/s with one stream and from 32 to 165 MB/s with eight, and slirp4netns CPU per MB fell
five- to six-fold. On Debian 10/11/12 test VMs, LAN transfers into a container rose 1.5–4× with every
driver PMSS hosts use today.

## Options Considered

- **Keep 1500.** No change, and the per-segment cost stays.
- **Switch drivers (pasta).** A larger change with different port-binding semantics, and issue #12
  kept slirp4netns.
- **Fill `mtu` and `default-network-opts` in daemon.json, keeping account values.** Plain `docker run`
  gains too, but every existing default-bridge container, including tunnel containers, changes MTU at
  its next daemon start.
- **Fill only `default-network-opts`, keeping account values.** Compose and other networks created
  afterwards gain; the default bridge that plain `docker run` uses, and every container already on it,
  stays at 1500.
- **Probe the running link MTU first.** It needs a running daemon, and on PMSS's own start path the
  answer is always 65520.

## Decision

`pmssUserRootlessDockerConfigConverge()` takes a `bridge_mtu` policy, and user maintenance passes
`PMSS_ROOTLESS_DOCKER_BRIDGE_MTU` (65520). The helper fills
`default-network-opts.bridge."com.docker.network.driver.mtu"` only when it is missing, and never sets
`mtu`, so the default bridge stays at 1500:
- A value the account set, valid or not, is kept, and policy values outside 1280–65535 are ignored.
- It fills a daemon.json that exists or that converge writes anyway (`create_when_missing` or another
  change). The MTU alone never creates the file.
- A file whose decoded form would not re-encode to the same JSON (an empty object such as
  `"log-opts": {}` becomes `[]`, which Docker refuses to load) is left untouched with a warning. This
  guard covers every converge write, not only the MTU. A file that decodes to nothing (`{}`) is
  safe, since the written file always gains keys.
- The daemon is not restarted for this, and existing networks are not recreated. Docker reads the key
  at the account daemon's next start: a reboot, the account restarting it, or an update that changes
  the Docker packages, which restarts every account's daemon in the same run
  (`scripts/lib/update/users/docker.php`). Only networks created after that start get 65520.

Engine floor: `default-network-opts` takes effect from Docker Engine 24. Older daemons start with the
key and ignore it (measured: 19.03.15, 20.10.24 and 23.0.6 start and create new networks at the
default; 24.0 creates them at 65520). PMSS installs `docker-ce` unpinned from download.docker.com,
whose newest builds are 26.1.4 for Debian 10 and 29.x for Debian 11/12. The key is still only
written when the installed `docker-ce` is 24 or later (`pmssPackageVersion()`), so a pre-24 build
that did refuse it could not stop every account's daemon on the host. A host held at an older
package keeps 1500, and the key is added at the first maintenance run after an upgrade to 24.

`etc/skel` is unchanged; converge fills new accounts' files on their first maintenance run.
`docker-install-wireguard.sh` puts its container on a network created at MTU 1500 (see below).

## Consequences

- **Positive:** container throughput on new networks rises with no driver change. Ordinary traffic
  leaves the host at the path MTU, because the user-space stack opens normal host sockets; tunnels
  inside containers are the exception (below).
- **Opting out:** only an explicit value holds: `"1500"` as the bridge network option. Deleting the
  key does not opt out; the next maintenance run fills it again. A single network can be kept at 1500
  with `docker network create --opt com.docker.network.driver.mtu=1500` or compose `driver_opts`.
- **Rollback:** removing the `bridge_mtu` policy line stops further filling but does not remove keys
  already written. Taking accounts back to 1500 needs either a converge step that removes exactly the
  PMSS value (`"65520"`), or per-account edits. Either way each daemon needs a restart and each
  network a recreate.
- **Negative:**
  - The default bridge stays at 1500, so plain `docker run` containers do not gain. An account can set
    `"mtu": 65520` itself.
  - Networks that already exist, such as compose `_default` and `pmss-media`, keep 1500 until they
    are recreated. A compose stack taken down and brought back up lands on a new network at 65520.
  - WireGuard sizes `wg0` from the container's `eth0` (MTU − 80). On a 65520 bridge `wg0` is 65440,
    and tunnel packets above 1420 bytes leave the host as IP fragments, which some paths drop
    (measured with the example image: nine 4–30 kB pings left as 90 IP fragments, against none at 1500).
    `docker-install-wireguard.sh` creates a `wireguard` network at 1500 and refuses an existing one at
    another MTU. Containers from the older script stay on the default bridge at 1500. Other tunnel
    images that size from `eth0` behave the same on new networks; `docs/docker-help.md` shows how to
    keep them at 1500.
  - An account that starts dockerd with a smaller link MTU (`DOCKERD_ROOTLESS_ROOTLESSKIT_MTU`) or
    with vpnkit gets new networks larger than the link. It still works, because path MTU discovery runs
    inside the namespace, but measured 475 against 770 MB/s at 1500/1500. Such an account sets `mtu`
    the network option itself and PMSS keeps it.
- **Debian 10/11 before the slirp4netns fix (#1120, a7532170 and ff03fa08):** PMSS installs no distro
  slirp4netns, and Debian 10's 0.2.3 would be below v0.4.0 anyway. Without the pinned helper on PATH,
  `dockerd-rootless.sh` exits before dockerd reads daemon.json, so the keys have no effect. A host
  where the helper was linked by hand, or that has the fix, gets 65520 like Debian 12.
- **Follow-up:** optionally, a panel or runbook note on recreating networks.

## References

- Issue #1121; issue #12 (rootless network driver evaluation); issue #1120.
- ADR 0027 (rootless Docker without the per-user systemd manager), ADR 0019 (cgroup v1 pin).
- `dockerd-rootless.sh` (moby `contrib/dockerd-rootless.sh`): driver order and MTU defaults.
- Docker daemon configuration: `mtu`, `default-network-opts`.
