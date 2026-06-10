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
rclone, qBittorrent, Deluge Web, lighttpd, and future services do not receive
the same loopback port. The allocator also treats legacy rTorrent reservation
files under `/var/lib/pmss/ports` as used slots. Assignments are guarded by a shared lock to avoid concurrent collisions and
mirrored to the shared user logs when available.
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
