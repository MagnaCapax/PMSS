<?php
/**
 * Package bootstrapper – orchestrates installer stacks defined under packages/.
 */

require_once __DIR__.'/packages/helpers.php';
require_once __DIR__.'/packages/system.php';
require_once __DIR__.'/packages/python.php';
require_once __DIR__.'/packages/misc.php';
require_once __DIR__.'/packages/mediaarea.php';
require_once __DIR__.'/packages/docker.php';

$version = isset($distroVersion) ? (int)$distroVersion : 0;

pmssInstallBaseTools();
pmssInstallProftpdStack($version);
pmssInstallSystemUtilities($version);
pmssInstallMediaAndNetworkTools($version);
pmssInstallPythonToolchain($version);
pmssInstallSabnzbd();
pmssInstallZncStack($version);
pmssInstallMiscTools();
pmssInstallWireguardPackages();
pmssInstallDockerPackages($version);
pmssInstallMediaAreaTools($version);
