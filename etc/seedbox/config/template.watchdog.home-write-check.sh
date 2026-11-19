#!/bin/sh
# PMSS watchdog test (ADR 0301): the logic is PHP; this shim only maps interpreter failures.
# The daemon reads a test's exit status literally and 255 means "reboot now", which is also
# what a PHP fatal, a parse error or a half-copied script exits with. Only 5 (stuck) passes.
[ "$1" = repair ] && exit 5
/usr/bin/php -n /etc/seedbox/config/template.watchdog.home-write-check.php test
[ "$?" -eq 5 ] && exit 5
exit 0
