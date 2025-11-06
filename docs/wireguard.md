# WireGuard Usage

PMSS installs and manages a single server endpoint at `/etc/wireguard/wg0.conf`.
During provisioning the installer generates server keys, enables `wg-quick@wg0`
and writes connection instructions to both `/etc/wireguard/README` and each
user's `~/wireguard.txt`.

Typical workflow:

1. Read `~/wireguard.txt` to obtain your server endpoint, public key, and
   configuration template.
2. Generate a client key pair (`wg genkey | tee private.key | wg pubkey > public.key`).
3. Share the public key with the administrator so it can be appended as a `[Peer]`
   in `/etc/wireguard/wg0.conf`. The template provides the MTU defaults; NAT and
   firewall rules are applied centrally by `/scripts/util/setupNetwork.php`, which
   runs automatically during updates.
4. Apply the client template on your device and set the private key.

A cron watchdog (`checkWireguard.php`) ensures the kernel module stays loaded and
`wg-quick@wg0` remains active. Logs are available in `/var/log/pmss/checkWireguard.log`.

Endpoint detection prefers resolving the host's FQDN and falls back to a public
IP lookup plus interface inspection. Make sure the hostname resolves externally
or update the generated `~/wireguard.txt` with the correct address if needed.

## End-User Quick Start

1. Download the contents of your `~/wireguard.txt` file (or copy it securely)
   and import it into your WireGuard client. Replace `<client private key>`
   with the private key you generated locally.
2. Keep the interface marked as a *Public/Untrusted* network profile on your
   operating system. All tenants share the `10.90.90.0/24` overlay so treating
   the link as a trusted LAN is unsafe unless you have explicit peer agreements.
3. Restrict local firewalls to the services you want reachable through the VPN.
   The server enforces NAT and forwarding centrally, so only the ports you
   expose on your device become reachable by other peers.
4. Regenerate a new client key pair and send the public key to support if your
   device is lost or compromised so the old peer entry can be revoked.

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
