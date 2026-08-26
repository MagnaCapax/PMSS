# Port Management Utility

The `portManager.php` script is used to manage HTTP server port assignments for user services.

```
Usage: portManager.php [view|assign|release] USER [SERVICE]
```

- **view** – show the assigned port
- **assign** – allocate a free port (if none assigned)
- **release** – free the port assignment

Port information is stored under `/etc/seedbox/runtime/ports` using files named `SERVICE-USER`.
New assignments choose from one shared namespace across all managed services, so
rclone, qBittorrent, Deluge Web, lighttpd, the native per-user media-stack apps,
and future services do not receive the same loopback port. The allocator also
treats legacy rTorrent reservation files under `/var/lib/pmss/ports` as used
slots and rejects candidates already bound on loopback or any IPv4 interface.
Assignments are guarded by a shared lock to avoid concurrent collisions and
mirrored to the shared user logs when available.

The customer-run `install-media-stack.sh` cannot access this root-owned store.
Root-side lighttpd convergence therefore reserves SABnzbd, Radarr, Prowlarr,
Sonarr, Autobrr, and Jellyfin ports and publishes user-readable
`~/.media-stack-port-APP` markers. Existing in-range app ports are adopted when
they are not listening or already reserved; otherwise the next installer run
converges the app and its proxy to a fresh managed port.
Invalid usernames and service names are rejected before any reservation path is built.
Persisted assignment files must contain a numeric TCP port; malformed existing
assignments are treated as errors for that user, while malformed sibling files
are ignored during free-port selection.
Tests may override the reservation directory with `PMSS_PORT_MANAGER_DIR` and the
legacy rTorrent reservation scan root with `PMSS_PORT_MANAGER_LEGACY_DIR`.

Example:

```
/scripts/util/portManager.php assign alice lighttpd
/scripts/util/portManager.php view alice lighttpd
/scripts/util/portManager.php release alice lighttpd
```
