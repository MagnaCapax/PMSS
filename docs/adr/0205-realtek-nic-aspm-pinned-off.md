# ADR 0205: PCIe ASPM pinned off on Realtek Ethernet controllers

Date: 2026-10-29
Category: architecture

## Status

Accepted

## Context

Some single-NIC hosts use the onboard Realtek RTL8111H/8168 controller with the in-kernel `r8169`
driver. Under load, one of them repeatedly stalls its transmit queue ("NETDEV WATCHDOG ... transmit
queue 0 timed out"), and the host stays off the network until the watchdog reboots it.

The same signature on an RTL8168h was reported upstream as a regression after `r8169` started
enabling ASPM L1/L1.1 on newer chip versions; `pcie_aspm=off` cured it for the reporter. On the
hosts measured here, the link already reports ASPM disabled under the current kernel and driver
defaults, and the stall has also occurred with ASPM disabled. So this rule does not fix the stall
seen here: it keeps the upstream mechanism ruled out if a later kernel or driver turns ASPM back on.

## Options Considered

- **Leave it to the kernel and driver defaults.** Today's defaults keep ASPM off, but they have
  changed before.
- **`pcie_aspm=off` on the kernel command line.** Global, needs a reboot, and the parameter leaves
  firmware-set ASPM unchanged rather than disabling it.
- **`pcie_aspm.policy=performance`.** Disables ASPM on every link in the host, not only the NIC.
- **A udev rule that writes `link/l1_aspm=0` (and `link/l0s_aspm=0` where present) for Realtek
  Ethernet devices.** Per device, no reboot, and re-applied after every driver bind.

## Decision

`update.php` installs `/etc/udev/rules.d/71-pmss-realtek-nic-aspm.rules`. The rule matches PCI
devices with vendor `0x10ec` and class `0x020000` on `add`, `bind` and `change`, and writes `0` to
each ASPM link attribute that exists. `bind` follows the driver probe, so a driver that enables ASPM
while probing is overridden. When the rule file changes, the update reloads udev and sends a `change`
event to the matching devices only.

## Consequences

- Hosts without a Realtek Ethernet controller only carry an inert rule file.
- The NIC link loses ASPM power saving.
- Hosts that do not let the OS control ASPM have no `link/*_aspm` attributes, so the rule does
  nothing there.
