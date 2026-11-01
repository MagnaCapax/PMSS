#!/usr/bin/env php
<?php
/**
 * Safe root-filesystem cleanup — reclaim REGENERABLE cruft ONLY, when / is near-full.
 *
 * On a shared PMSS host whose ROOT filesystem approaches 100% from accumulated cruft
 * (root full for days while /home has ample free space and the arrays are healthy —
 * an OS-root exhaustion, not a hardware failure), free space WITHOUT a blanket rm.
 *
 * Whitelist-ONLY, hardcoded regenerable classes:
 *   - apt package cache            (apt-get clean)
 *   - journald journal over a cap  (journalctl --vacuum-size)
 *   - ROTATED logs (never live)    (/var/log/**  *.gz / *.N / *.N.gz / *.old, older than N days)
 *   - dist-upgrade scratch         (/var/tmp/mkinitramfs_*)
 *   - old atop logs                (/var/log/atop/atop_* older than N days)
 *
 * NEVER touches /home, /var/www, customer data, /etc, /root, /boot, live logs, or any
 * unrecognized/served file. The hardcoded whitelist + a realpath deny/allow gate IS the
 * safety boundary (POLA-correct). Dry-run by DEFAULT; --apply frees and logs every byte.
 *
 * NOT a live-log-storm tool: a filling LIVE syslog/kern.log (e.g. a runaway process
 * OOM-looping into the log) is fixed by stopping the source, not by this cleanup.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/rootCleanup/cli.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssSafeRootCleanupMain');
