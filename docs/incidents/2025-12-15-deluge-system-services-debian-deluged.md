# Incident Report: Unauthorized Unprivileged Execution via Exposed Debian Deluge System Services (debian-deluged)

**Date (detected):** 2025-12-15  
**Affected component:** Debian Deluge system services and defaults (deluged, deluge-web, debian-deluged user)  
**Impact:** Unauthorized compute use via unprivileged execution as debian-deluged on one host (confirmed)

---

## Executive Summary

Debian's Deluge packages ship with system-wide systemd services and a web-based administrative control plane (deluge-web). When those services are reachable over a network and not explicitly hardened, they represent an unsafe default posture.

PMSS is designed for Deluge to run per-user only (under /home/<user>, supervised by PMSS tooling), not as a global system service. A regression in PMSS service convergence logic allowed Debian's Deluge system services to remain enabled and running on some hosts, exposing an unprivileged control plane owned by the debian-deluged account.

On one host, that exposure was abused to run a long-lived crypto-mining workload (xmrig-style) under debian-deluged. The activity was detected, contained, and eliminated. PMSS has since enforced stop + disable + mask for the relevant system services, added continuous drift correction, added boot-time enforcement, and strengthened network-level defense in depth to prevent recurrence.

---

## Impact and Scope

- **Confirmed scope:** One host.
- **Fleet status:** Sample-based fleet scan and remediation run performed.
- **Privileges:** All observed execution occurred under the unprivileged debian-deluged account.
- **No indicators found of:** root-level compromise, privilege escalation, or lateral movement between users.
- **Customer impact:** Unauthorized CPU usage on the affected host. No indicators found of data exfiltration beyond what the Deluge service account could normally access.

---

## Detection

The incident was surfaced during investigation of unauthorized compute activity. A long-running mining process was identified, and process ownership immediately pointed to the debian-deluged service account. Investigation then focused on:

- active systemd services
- Deluge system service configuration
- Deluge web UI exposure
- persistence mechanisms within Deluge configuration

---

## Technical Details

On the confirmed affected host, we observed:

- Debian's Deluge system services (deluged and deluge-web) running as debian-deluged, rather than per-user daemons under /home/<user>.
- deluge-web configured to bind to 0.0.0.0, meaning it could accept non-local connections if the firewall allowed it.
- A long-running unauthorized crypto-mining process executing under debian-deluged.
- Deluge configuration indicating a plugin or command path used to execute a dropped script.

### Deluge default behavior relevant to this incident

- Deluge Web UI defaults to password `deluge`.
- If the Deluge configuration directory does not exist or is removed, defaults are regenerated, including the default password.

This forms a straightforward unprivileged execution chain:

1. System-wide Deluge services enabled and running.
2. Deluge Web UI reachable over a network.
3. Web UI accessible via default credentials due to default configuration behavior.
4. Control-plane abuse via Deluge configuration or plugin surface.
5. Execution of a locally dropped payload as debian-deluged.

All observed activity remained confined to the unprivileged service account.

---

## Root Cause

### 1. Unsafe upstream defaults on network-reachable hosts

Debian's Deluge defaults are not safe when system services are reachable over a network without explicit hardening:

- Deluge ships with system-wide services that can be enabled and started.
- deluge-web is a full administrative control plane.
- Default credentials (deluge) are present if configuration is regenerated.
- When exposed, the plugin and command surfaces allow configuration-driven execution paths.

This risk applies to any network-reachable host, not just internet-facing systems.

Shipping a system-wide administrative control plane with default credentials is unsafe in any environment:
- public internet
- private networks
- internal VLANs
- management backplanes
- trusted LANs

Network reachability alone is sufficient to make such defaults unacceptable. Multi-tenant hosts amplify the blast radius, but even single-tenant systems are exposed to lateral movement, guest access, misconfiguration, or accidental exposure.

System-wide administrative services with default access are treated as hostile by PMSS regardless of deployment context.

---

### 2. PMSS regression: incomplete system service convergence

PMSS policy is explicit: torrent applications run per-user. Debian's system services must be neutralized.

A regression in PMSS update and install logic meant that:

- Deluge system services were disabled only during certain install paths.
- When Deluge packages were already present (historical installs, dpkg baselines, or upgrade paths), PMSS did not consistently enforce:
  - systemctl stop
  - systemctl disable
  - systemctl mask
- deluge-web was not consistently covered.

This allowed some hosts to drift into a state where system services were enabled and persisted across reboots.

---

### 3. Why this surfaced now

Deluge packages have existed on parts of the fleet for years. What changed recently was likelihood, not existence:

- Baseline refreshes increased the consistency with which Deluge packages and their unit files were present.
- A convergence regression meant system services were not forcibly driven to a safe state once packages existed.
- systemd enablement state differs by host history, so not all nodes were affected.

Relevant PMSS changes for traceability:

- Debian 11 dpkg baseline adding Deluge packages: commit 68bb7f42f4b8397904f06b89f846c2fde99e7d3a (2025-09-23)  
  https://github.com/MagnaCapax/PMSS/commit/68bb7f42f4b8397904f06b89f846c2fde99e7d3a
- Debian 12 dpkg baseline refresh and validation including Deluge packages: commit 5de85a7aa2ab35ec2ea7c78473a805722e82b681 (2025-09-23)  
  https://github.com/MagnaCapax/PMSS/commit/5de85a7aa2ab35ec2ea7c78473a805722e82b681
- Deluge installer idempotence change reducing enforcement on already-installed packages: commit 05e7b6bad40be991e6e8b4b74e3792170d6e8a58 (2025-11-06)  
  https://github.com/MagnaCapax/PMSS/commit/05e7b6bad40be991e6e8a58

---

## Corrective Actions Implemented (PMSS)

### Immediate containment

- Backend automation was used to:
  - Stop and disable Deluge system services on systems where they were found running.
  - Capture and quarantine indicators (binaries and configuration) on the confirmed affected host.

A sample of additional hosts was inspected; some were found with Deluge system services running but no indicators found of compromise. All identified instances were remediated.

---

### Centralized systemd hardening

PMSS now enforces service policy via scripts/lib/update/services/systemd.php:

- stop + disable + mask for system-wide services that must not be exposed on PMSS nodes, including:
  - deluged
  - deluge-web
  - qbittorrent-nox
  - transmission-daemon
  - redis-server
  - memcached
  - rpcbind and nfs-*
  - smbd
  - avahi-daemon
  - cups
  - exim4
  - docker.service, docker.socket, containerd
  - legacy apache2 and system lighttpd

Masking prevents start or enable unless explicitly unmasked.

---

### Boot-time enforcement

PMSS installs and enables a boot-time systemd unit so the hardening guard runs early during boot:

- Template: etc/seedbox/config/template.systemd.pmss-systemd-services-guard.service
- Install and enable path: pmssEnsureSystemdServicesGuardBootUnit(), wired in scripts/util/update-step2.php

This ensures policy enforcement even before cron or user services start.

---

### Update pipeline enforcement

- update-step2 reapplies system service hardening:
  - early in the update run
  - again after application installers

This prevents transient exposure during updates.

---

### Continuous drift correction (ongoing detection)

A dedicated drift guard has been added:

- scripts/cron/systemdServicesGuard.php
- Corresponding entries in etc/seedbox/config/root.cron

This guard periodically enforces policy defined in pmssSeedboxSystemServiceSpecs() and ensures unwanted system-wide services remain stopped, disabled, and masked, even if reintroduced by package manager actions or manual operations.

This provides continuous detection and correction, not a one-time fix.

---

### Network-level defense in depth

scripts/util/setupNetwork.php explicitly blocks inbound access to common default torrent Web UI ports on the public interface:

- Deluge Web UI (8112)
- qBittorrent Web UI (8080)

This ensures that even if a service were mistakenly enabled, the control plane would not be reachable over the network.

---

## Role of Rolling Updates

PMSS is deployed using rolling updates across the fleet rather than simultaneous mass upgrades. This limits blast radius and surfaces regressions early.

In this incident:

- The confirmed compromise was limited to a single host.
- Other hosts running different update states or upgrade timing were not confirmed compromised.
- This enabled rapid containment, analysis, and correction without widespread service disruption.

Rolling updates are a deliberate operational safeguard in PMSS. They ensure that unexpected interactions between upstream packages, system services, and PMSS orchestration logic are observed on a small subset of hosts first, rather than impacting the entire fleet simultaneously.

This incident validates that approach.

---

## PMSS Doctrine Reinforced

- System-wide services are hostile by default on network-reachable hosts.
- Torrent applications run per-user only under PMSS.
- Safe state is enforced through:
  - stop, disable, and mask at the systemd level
  - reassertion during updates
  - continuous drift correction
  - network-level blocking of known control-plane ports

---

## Takeaways

- Unsafe upstream defaults must be actively neutralized.
- Disable once is insufficient; convergence must be continuous.
- Unprivileged execution still matters operationally, even without root access.

The conditions that allowed Debian's Deluge system services to run in this configuration have now been removed from PMSS, and the hardening is continuously enforced across the fleet.
