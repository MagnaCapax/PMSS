# Recovery Procedures

## Critical: Failed Dist-Upgrade (Debian 10 to 11)

**Symptoms:**
- SSH connection dropped during upgrade.
- Server is "stuck" or inaccessible.
- PHP is reported missing/uninstalled.
- `dpkg` halted on configuration prompts (e.g., `proftpd.conf`).

### Immediate Recovery Steps (Break-Glass)

If SSH is unavailable, you must access the server via your provider's KVM, IPMI, or VNC console.

1.  **Gain Shell Access:**
    Log in as `root` on the console.

2.  **Fix Interrupted Package Manager:**
    The upgrade likely stalled holding a lock. Reset it:
    ```bash
    # Kill any suspended apt/dpkg processes if necessary
    killall apt apt-get dpkg
    
    # Resume configuration of unpacked packages
    export DEBIAN_FRONTEND=noninteractive
    dpkg --configure -a
    ```

3.  **Restore Network (If lost):**
    If `ifconfig` or `ip addr` shows no IP or only `lo`:
    - Check interface names: `ls /sys/class/net`
    - If names changed (e.g., `eth0` → `ens3`), update `/etc/network/interfaces`.
    - Restart networking: `systemctl restart networking`

4.  **Restore PHP Interpreter:**
    The upgrade may have removed `php7.3` before fully installing `php7.4`.
    ```bash
    apt-get update
    apt-get install -y php-cli php-curl php-json php-xml php-mbstring php-zip
    ```

5.  **Resume/Finish Upgrade:**
    ```bash
    apt-get -f install
    apt-get full-upgrade -y
    ```

6.  **Verify Critical Services:**
    ```bash
    systemctl status sshd
    systemctl status proftpd
    systemctl status nginx
    ```

### Troubleshooting Specific Components

#### ProFTPD Halt
If `proftpd` blocks repeatedly:
```bash
# Force the "old" config to be kept without prompting
apt-get install -o Dpkg::Options::="--force-confold" proftpd-basic
```

#### PHP Missing
If `php` command is not found:
```bash
ls -l /usr/bin/php*
update-alternatives --config php
# Select the valid version (likely /usr/bin/php7.4)
```
