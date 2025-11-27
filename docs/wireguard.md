# WireGuard Usage

PMSS installs and manages a single server endpoint at `/etc/wireguard/wg0.conf`.
During provisioning the installer generates server keys, enables `wg-quick@wg0`
and writes connection instructions to both `/etc/wireguard/README` and each
user's `~/wireguard.txt`.

This document describes the **host-level** WireGuard service managed by PMSS.
Some accounts also use the optional linuxserver.io WireGuard container via
Docker (see [`docs/docker-help.md`](./docker-help.md) and
[`docs/linuxserver.io.md`](./linuxserver.io.md)); that container runs under
your user account and is separate from the system `wg0` service covered here.

Typical workflow:

1. Read `~/wireguard.txt` to obtain your server endpoint, public key, and
   configuration template.
2. Generate a client key pair (`wg genkey | tee private.key | wg pubkey > public.key`).
3. On the seedbox, store your public key in `~/.wireguard-public-key` (one key
   per line). The updater periodically rebuilds `/etc/wireguard/wg0.conf` from
   these files, adding a `[Peer]` entry for every valid key.
4. Apply the client template on your device and set the private key. The server
   assigns each key a unique `/32` address inside `10.90.90.0/24`; peers are not
   treated as a trusted LAN, and routing between tenants is controlled centrally.

A cron watchdog (`checkWireguard.php`) ensures the kernel module stays loaded and
`wg-quick@wg0` remains active. Logs are available in `/var/log/pmss/checkWireguard.log`.

Endpoint detection prefers resolving the host's FQDN and falls back to a public
IP lookup plus interface inspection. Make sure the hostname resolves externally
or update the generated `~/wireguard.txt` with the correct address if needed.

## Tenant Quick Start (Step by Step)

This section is intended to be copy-pasteable for end users.

1. **Install a WireGuard client on your device**
   - Linux: install the `wireguard-tools` package or use your distro's WireGuard app.
   - Windows/macOS/mobile: install the official "WireGuard" app from the vendor store.

2. **Log in to your seedbox via SSH**
   - Use the username and password (or SSH key) provided for your account.

3. **Generate a client key pair on your own device**

   On Linux/macOS (in a local terminal, *not* on the seedbox):

   ```bash
   umask 077
   wg genkey | tee private.key | wg pubkey > public.key
   ```

   - `private.key` must never be shared.
   - `public.key` will be stored on the seedbox account.

4. **Register your public key on the seedbox**

   On the seedbox (SSH session, as your user):

   ```bash
   echo "$(cat public.key)" >> ~/.wireguard-public-key
   ```

   - One key per line; you can add multiple lines for multiple devices.
   - Invalid lines are ignored by the server and do not affect other peers.
   - Within a few minutes the server will detect the new key and refresh its
     WireGuard configuration.

5. **Fetch your base configuration from the seedbox**

   - On the seedbox, view your `~/wireguard.txt` file:

     ```bash
     cat ~/wireguard.txt
     ```

   - Copy the entire contents to your clipboard or download the file via SFTP.

6. **Create a tunnel in your WireGuard client**

   - In the WireGuard app, create a new tunnel and paste the contents of
     `wireguard.txt` into the configuration editor.
   - In the `[Interface]` section, replace the placeholder:

     ```ini
     PrivateKey = <client private key>
     ```

     with the contents of your local `private.key` file from step 3.

7. **Treat the VPN as untrusted and lock down services**

   - Keep the WireGuard interface marked as a *Public/Untrusted* network in your
     OS. All tenants share the `10.90.90.0/24` overlay; do not treat it as a
     trusted LAN.
   - Configure your local firewall to only expose the services you want reachable
     over the VPN.
   - The server enforces NAT and forwarding centrally, so only ports you expose
     on your device become reachable by other peers.

8. **Rotate keys when devices are lost or compromised**

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
  `PMSS_WG_PRIVATE_KEY` / `PMSS_WG_PUBLIC_KEY` for deterministic key material.
  Use these to exercise new code paths without touching the real filesystem.
