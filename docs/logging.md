# Central Log Shipping

PMSS supports optional forwarding of system logs to a central syslog server.
This feature is **disabled by default** and must be explicitly enabled through
configuration.

## Overview

The log shipping infrastructure uses rsyslog with the `imjournal` module to
read logs from systemd's journal and forward them to a remote server. This
approach ensures compatibility with systemd-based logging while maintaining
compatibility with traditional syslog infrastructure.

Key characteristics:

- **Disabled by default**: No remote logging occurs unless explicitly configured
- **Best-effort only**: Logging configuration failures never abort PMSS updates
- **No external dependencies**: Uses rsyslog which is part of the Debian baseline
- **systemd compatible**: Reads from journald via imjournal module
- **Queue persistence**: Logs are queued locally if the remote server is unreachable

## Configuration

Create `/etc/seedbox/config/logging.conf` to enable remote logging:

```ini
# Enable remote log forwarding
remote_logging_enabled=1

# Remote syslog server hostname or IP address
remote_host=logserver.example.com

# Remote syslog server port (default: 514)
remote_port=514

# Protocol: tcp or udp (default: tcp)
remote_protocol=tcp
```

### Configuration Options

| Option | Required | Default | Description |
|--------|----------|---------|-------------|
| `remote_logging_enabled` | Yes | `0` | Set to `1`, `true`, `yes`, or `on` to enable |
| `remote_host` | Yes | (none) | Hostname or IP of the central log server |
| `remote_port` | No | `514` | Port number (1-65535) |
| `remote_protocol` | No | `tcp` | Either `tcp` or `udp` |

### Disabling Remote Logging

To disable remote logging after it has been enabled:

1. Either delete `/etc/seedbox/config/logging.conf`
2. Or set `remote_logging_enabled=0`

The next PMSS update will automatically remove the rsyslog forwarding config
and restart rsyslog to stop forwarding.

## Deployment

Remote logging configuration is applied during PMSS updates:

```
php /scripts/update.php git/main
```

Or with scripts-only for a lightweight refresh:

```
php /scripts/update.php git/main --scripts-only
```

The update deploys the rendered configuration to `/etc/rsyslog.d/50-pmss-remote.conf`
and restarts rsyslog to apply changes.

## How It Works

1. **Configuration parsing**: During update, PMSS reads `logging.conf` if it exists
2. **Validation**: Verifies required fields (enabled flag, valid host)
3. **Template rendering**: Substitutes placeholders in the rsyslog template
4. **Deployment**: Writes config to `/etc/rsyslog.d/50-pmss-remote.conf`
5. **Service restart**: Restarts rsyslog to apply the new configuration

The rsyslog template uses:

- `imjournal` module: Reads logs directly from systemd journal
- `omfwd` action: Forwards logs to remote server
- Queue persistence: Logs are saved to disk if remote is unreachable

## Troubleshooting

### Logs not arriving at central server

1. Verify configuration syntax in `logging.conf`:
   ```
   cat /etc/seedbox/config/logging.conf
   ```

2. Check rsyslog configuration was deployed:
   ```
   cat /etc/rsyslog.d/50-pmss-remote.conf
   ```

3. Verify rsyslog is running:
   ```
   systemctl status rsyslog
   ```

4. Check rsyslog logs for errors:
   ```
   journalctl -u rsyslog -n 50
   ```

5. Test network connectivity to the log server:
   ```
   nc -zv logserver.example.com 514
   ```

### Configuration not being applied

Check the PMSS update log for warnings:

```
grep -i logging /var/log/pmss/update.log
```

Common issues:

- `logging.conf` not readable by root
- Invalid hostname format
- rsyslog.d directory does not exist (rsyslog not installed)

### Removing stale configuration

If remote logging was enabled and then the configuration file was deleted
without running an update, manually remove the rsyslog config:

```
rm /etc/rsyslog.d/50-pmss-remote.conf
systemctl restart rsyslog
```

## Security Considerations

- Use TCP protocol (default) for reliable delivery; UDP may drop logs under load
- Consider TLS-encrypted syslog if forwarding over untrusted networks
- Logs may contain sensitive information; ensure the central server is secured
- The remote server address is stored in plain text in `logging.conf`

## Files

| Path | Purpose |
|------|---------|
| `/etc/seedbox/config/logging.conf` | User configuration (create to enable) |
| `/etc/seedbox/config/template.rsyslog-remote.conf` | PMSS template for rsyslog config |
| `/etc/rsyslog.d/50-pmss-remote.conf` | Deployed rsyslog forwarding config |
| `/var/spool/rsyslog/pmss_remote_queue` | Queue directory for offline logs |

## Architecture

```
                           PMSS Host
+---------------------------------------------------------------+
|                                                               |
|  systemd-journald  ──────►  rsyslog  ──────►  Central Server  |
|       │                        │                    │         |
|       │ writes                 │ reads via          │ TCP/UDP |
|       ▼                        │ imjournal          │ port 514|
|  /var/log/journal/             │                    ▼         |
|                                │              ┌───────────┐   |
|                                │              │ Log Server│   |
|                                │              └───────────┘   |
|                                │                              |
|                        /etc/rsyslog.d/                       |
|                        50-pmss-remote.conf                   |
|                                                               |
+---------------------------------------------------------------+
```

The `imjournal` module reads entries from the systemd journal, which receives
logs from:

- systemd services (nginx, proftpd, cron, etc.)
- Kernel messages
- User services via syslog()
- Applications writing to journal directly
