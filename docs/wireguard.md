# WireGuard Usage

PMSS installs and manages a single server endpoint at `/etc/wireguard/wg0.conf`.
During provisioning the installer generates server keys, enables `wg-quick@wg0`
and writes connection instructions to `/etc/wireguard/README` plus a ready-to-
import single-device profile to each user's `~/wireguard.txt`.

This document describes the **host-level** WireGuard service managed by PMSS.
Some accounts also use the optional linuxserver.io WireGuard container via
Docker (see [`docs/docker-help.md`](./docker-help.md) and
[`docs/linuxserver.io.md`](./linuxserver.io.md)); that container runs under
your user account and is separate from the system `wg0` service covered here.

Typical workflow:

1. Download `~/wireguard.txt` via SFTP or view it over SSH.
2. Import the file directly into the WireGuard app on one device.
3. Treat `~/wireguard.txt` as a secret: it contains a server-generated client
   private key and is stored with mode `0600` on the seedbox.
4. For extra devices or for client-only key generation, add additional public
   keys to `~/.wireguard-public-key`. The updater periodically rebuilds
   `/etc/wireguard/wg0.conf` from these files, adding a `[Peer]` entry for every
   valid key.

If a PMSS-generated single-device `~/wireguard.txt` already exists but its
matching `~/.wireguard-public-key` entry goes missing, the periodic refresh can
re-register that first profile automatically from the managed guide.
Periodic address reconciliation likewise updates that profile only when its
embedded private key derives to the registered public key receiving the address;
additional device keys cannot retarget the shared bootstrap profile.

A cron watchdog (`checkWireguard.php`) ensures the kernel module stays loaded,
`wg-quick@wg0` remains active, and configured peers are loaded into the running
interface. Logs are written to `/var/log/pmss/checkWireguard.log` when taking
action (module load/restart/peer sync); use `checkWireguard.php --debug` to also
log healthy checks.

Endpoint detection prefers a resolvable host FQDN in generated client profiles
and falls back to a public IP lookup plus interface inspection when the hostname
cannot resolve. WireGuard re-resolves the hostname when the tunnel starts, so
restart the tunnel after a server address change.

## Tenant Quick Start (Step by Step)

This section is intended to be copy-pasteable for end users.

1. **Install a WireGuard client on your device**
   - Linux: install the `wireguard-tools` package or use your distro's WireGuard app.
   - Windows/macOS/mobile: install the official "WireGuard" app from the vendor store.

2. **Import the pre-generated profile**

   - Download `~/wireguard.txt` from your seedbox account.
   - Import it directly into the WireGuard app.
   - The file already includes the private key, endpoint, and a per-device `/32`
     address, so no editing is required for the first device.

3. **Protect the imported file**

   - Anyone who can read `~/wireguard.txt` can use that client identity.
   - If the file is copied to an unsafe location, rotate the corresponding line
     in `~/.wireguard-public-key` and let the updater refresh the peer set.

4. **Use the manual flow for additional devices (optional)**

   - Log in to your seedbox via SSH.
   - Use the username and password (or SSH key) provided for your account.

5. **Generate a client key pair on your own device**

   On Linux/macOS (in a local terminal, *not* on the seedbox):

   ```bash
   umask 077
   wg genkey | tee private.key | wg pubkey > public.key
   ```

   - `private.key` must never be shared.
   - `public.key` will be stored on the seedbox account.

6. **Register your public key on the seedbox**

   On the seedbox (SSH session, as your user):

   ```bash
   echo "$(cat public.key)" >> ~/.wireguard-public-key
   ```

   - One key per line; you can add multiple lines for multiple devices.
   - Invalid lines are ignored by the server and do not affect other peers.
   - Within a few minutes the server will detect the new key and refresh its
     WireGuard configuration.

7. **Keep the generated profile as your first-device baseline**

   - PMSS only auto-generates the first client profile in `~/wireguard.txt`.
   - Additional devices remain an advanced/manual workflow; keep the generated
     profile intact so you can still import it directly when needed.

8. **Treat the VPN as untrusted and lock down services**

   - Keep the WireGuard interface marked as a *Public/Untrusted* network in your
     OS. All tenants share the `10.90.90.0/24` overlay; the server drops direct
     peer-to-peer traffic on `wg0`, but you must still treat the VPN as
     untrusted and apply your own firewalling.
   - Configure your local firewall to only expose the services you want reachable
     over the VPN.
   - The server enforces NAT and forwarding centrally, so only ports you expose
     on your device become reachable by other peers.

9. **Rotate keys when devices are lost or compromised**

   - Generate a new key pair, update the corresponding line in
     `~/.wireguard-public-key`, and update your client config with the new
     private key.
   - Remove any obsolete lines from `~/.wireguard-public-key` to revoke access
     for old devices.

## Developer Notes

- The WireGuard installer is covered by hermetic tests in
  `scripts/lib/tests/development/WireGuardInstallerTest.php`. These tests seed
  behaviour via environment overrides such as `PMSS_WG_DNS_IP` (mock DNS
  resolution), `PMSS_WG_EXTERNAL_IP` (stub public IP helper),
  `PMSS_WG_INTERFACE_IP` (fake uplink address), and `PMSS_WG_USER_LIST`
  (synthetic tenant roster).
- Additional overrides include `PMSS_WG_CONFIG_DIR` for staging config output
  inside `/tmp`, `PMSS_WG_HOME_BASE` for per-user file fan-out, and
  `PMSS_WG_PRIVATE_KEY` / `PMSS_WG_PUBLIC_KEY` for deterministic server key
  material plus `PMSS_WG_CLIENT_PRIVATE_KEY` / `PMSS_WG_CLIENT_PUBLIC_KEY` for
  deterministic bootstrap client profiles. Use these to exercise new code paths
  without touching the real filesystem.
