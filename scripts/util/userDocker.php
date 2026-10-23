#!/usr/bin/env php
<?php
/**
 * Control one account's rootless Docker daemon or transferred containers.
 * Usage: userDocker.php USER {start|stop|restart|status|start-containers} [ID ...] [--debug]
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/user/docker/cli.php';
pmssRequireCli();
exit(userDockerMain($argv));
