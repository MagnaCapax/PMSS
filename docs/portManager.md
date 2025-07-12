# Port Management Utility

The `portManager.php` script is used to manage HTTP server port assignments for user services.

```
Usage: portManager.php [view|assign|release] USER [SERVICE]
```

- **view** – show the assigned port
- **assign** – allocate a free port (if none assigned)
- **release** – free the port assignment

Port information is stored under `/etc/seedbox/runtime/ports` using files named `SERVICE-USER`.

Example:

```
/scripts/util/portManager.php assign alice lighttpd
/scripts/util/portManager.php view alice lighttpd
/scripts/util/portManager.php release alice lighttpd
```
