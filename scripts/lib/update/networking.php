<?php
/**
 * Networking helpers for update-step2.
 *
 * Template mapping:
 *   - `setupNetwork.php` consumes `/etc/seedbox/config/template.fireqos` and
 *     replaces placeholders (`##IFACE##`, `##LINK##`, `##USERMATCHES##`) based on
 *     the values returned by `networkLoadConfig()`.
 *   - Local network CIDRs from `networkLoadLocalnets()` populate
 *     `##LOCALNETWORK` blocks so FireQOS exempts internal traffic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/managedPath.php';

/**
 * Seed the default network configuration file when missing.
 */
function pmssEnsureNetworkTemplate(?callable $logger = null): void
{
    $log  = $logger ?: 'logMessage';
    $path = getenv('PMSS_NETWORK_CONFIG');
    $path = is_string($path) && trim($path) !== ''
        ? rtrim($path, '/')
        : '/etc/seedbox/config/network';
    if (file_exists($path)) {
        return;
    }

    $template = <<<PHP
<?php
#Default settings, change these to suit your system. Speeds are in mbits
return array(
    'interface' => 'eth0',
    'speed' => '1000',
    'throttle' => array(
      'min' => 50,
      'max' => 100,
      'progressiveThrottleEnabled' => true,
      'progressiveThrottleFloorPercent' => 2.5,
      'progressiveThrottleGracePercent' => 0,
      'overageStages' => array(
        array('overagePercent' => 200, 'capMbit' => 1),
        array('overagePercent' => 125, 'capMbit' => 1),
        array('overagePercent' => 100, 'capMbit' => 10),
        array('overagePercent' => 75, 'minOverageGiB' => 5120, 'capMbit' => 25),
        array('overagePercent' => 50, 'minOverageGiB' => 3072, 'capMbit' => 50),
      ),
      'soft' => 250,
      'limitSoft' => 80,
      'limitExceedMax' => 20

    )

);
PHP;

    if (!pmssWriteManagedPathFile($path, $template, 'network configuration', $log)) {
        return;
    }
    $log('Created default network configuration');
}
