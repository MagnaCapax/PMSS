# Network Configuration Script

`setupNetwork.php` applies firewall and traffic shaping rules for all seedbox users. It reads settings from `/etc/seedbox/config/network` and optional `/etc/seedbox/config/localnet`.
If the `localnet` file is missing a default range of `185.148.0.0/22` is
created so local traffic can be tracked separately.

There are no command line arguments; simply run:

```
/scripts/util/setupNetwork.php
```

Actions performed include:
- installing iptables monitoring rules
- enabling forwarding and NAT for VPN tunnels
- filtering bogon networks
- generating a FireQOS configuration for user bandwidth limits

This script is usually run after user modifications or during system updates.

**Documentation quality**: The code implements many networking tweaks but lacks inline comments or a high level overview. More documentation on expected config values would be beneficial.
