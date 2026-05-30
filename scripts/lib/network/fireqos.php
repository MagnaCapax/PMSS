<?php
/**
 * FireQOS configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../user/identity.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';

function networkBuildFireqosConfig(array $networkConfig, array $users, array $localnets): string
{
    $templatePath = pmssResolvePathFromEnv('PMSS_FIREQOS_TEMPLATE', '/etc/seedbox/config/template.fireqos');
    $template = is_file($templatePath) ? @file_get_contents($templatePath) : false;
    if ($template === false) {
        $template = "interface ##INTERFACE\nrate ##SPEED\n##LOCALNETWORK\n##USERMATCHES\n";
    }

    $fireqosConfigLocal = "class local commit 10%\n";
    foreach ($localnets as $localnet) {
        $fireqosConfigLocal .= "    match dst {$localnet}\n";
    }

    $fireqosConfigUsers = '';
    $fireqosMark = 1;
    $limitStateDir = pmssResolvePathFromEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', '/var/run/pmss/trafficLimits');
    $homeDir = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
    $configuredCapMbit = $networkConfig['throttle']['max'] ?? 100;
    $defaultCapMbit = is_numeric($configuredCapMbit) && (int) $configuredCapMbit > 0
        ? (int) $configuredCapMbit
        : 100;

    foreach ($users as $username) {
        if (!pmssValidateUsername($username)) {
            continue;
        }

        $uid = trim((string) @shell_exec('id -u '.escapeshellarg($username).' 2>/dev/null'));
        if (!ctype_digit($uid)) {
            continue;
        }

        $limit = '';
        if (is_file($limitStateDir."/{$username}.enabled")) {
            $capMbit = $defaultCapMbit;
            $throttlePath = $homeDir."/{$username}/.throttle";
            $stored = (int) (pmssReadRegularFileDigits($throttlePath) ?? '0');
            if ($stored > 0) {
                $capMbit = $stored;
            }
            $limit = ' ceil '.$capMbit.'Mbit';
        }

        $fireqosConfigUsers .= "    class {$username}{$limit} \n";
        $fireqosConfigUsers .= "      match rawmark {$fireqosMark}\n";
        ++$fireqosMark;
    }

    $rendered = str_replace(
        ['##INTERFACE', '##SPEED', '##LOCALNETWORK', '##USERMATCHES'],
        [
            $networkConfig['interface'] ?? 'eth0',
            $networkConfig['speed'] ?? 1000,
            $fireqosConfigLocal,
            $fireqosConfigUsers
        ],
        $template
    );

    return $rendered;
}

function networkApplyFireqos(string $config): void
{
    $configPath = pmssResolvePathFromEnv('PMSS_FIREQOS_CONFIG_PATH', '/etc/seedbox/config/fireqos.conf');
    $logPath = pmssResolvePathFromEnv('PMSS_FIREQOS_LOG_PATH', '/var/log/pmss/fireqos.log');

    // Ensure the rendered policy is on disk before attempting a restart.
    if (!pmssDirEnsureExists(dirname($configPath), 0755) || !pmssDirEnsureExists(dirname($logPath), 0755)) {
        return;
    }
    if (!pmssAtomicWriteFile($configPath, $config, 0644)) {
        return;
    }

    if (pmssCommandPath('fireqos') !== '') {
        @shell_exec('fireqos start '.escapeshellarg($configPath).' >> '.escapeshellarg($logPath).' 2>&1');
    }
}
