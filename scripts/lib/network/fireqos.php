<?php
/**
 * FireQOS configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../userLifecycle.php';

/**
 * Resolve a validated username to its uid for FireQOS class generation.
 */
function networkFireqosLookupUid(string $username): ?int
{
    if (!pmssValidateUsername($username)) {
        return null;
    }

    $uid = trim((string) @shell_exec('id -u '.escapeshellarg($username).' 2>/dev/null'));
    return ctype_digit($uid) ? (int) $uid : null;
}

function networkBuildFireqosConfig(array $networkConfig, array $users, array $localnets): string
{
    $templatePath = getenv('PMSS_FIREQOS_TEMPLATE') ?: '/etc/seedbox/config/template.fireqos';
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
    $limitStateDir = getenv('PMSS_TRAFFIC_LIMIT_STATE_DIR') ?: '/var/run/pmss/trafficLimits';
    $homeDir = getenv('PMSS_HOME_DIR') ?: '/home';
    $defaultCapMbit = 100;
    if (isset($networkConfig['throttle']['max']) && is_numeric($networkConfig['throttle']['max'])) {
        $defaultCapMbit = (int) $networkConfig['throttle']['max'];
    }
    if ($defaultCapMbit <= 0) {
        $defaultCapMbit = 100;
    }
    $readPositiveInt = function (string $path): ?int {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $raw = trim((string) @file_get_contents($path));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $value = (int) $raw;
        return $value > 0 ? $value : null;
    };

    foreach ($users as $username) {
        if (networkFireqosLookupUid($username) === null) {
            continue;
        }

        $limit = '';
        $slidingPath = $limitStateDir."/{$username}.throttle_mbit";
        $stored = $readPositiveInt($slidingPath);
        if ($stored !== null) {
            $limit = ' ceil '.$stored.'Mbit';
        }
        if ($limit === '' && is_file($limitStateDir."/{$username}.enabled")) {
            $capMbit = $defaultCapMbit;
            $throttlePath = $homeDir."/{$username}/.throttle";
            $stored = $readPositiveInt($throttlePath);
            if ($stored !== null) {
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
    // Ensure target paths exist and FireQOS is available before attempting start.
    if (!is_dir('/etc/seedbox/config')) {
        @mkdir('/etc/seedbox/config', 0755, true);
    }
    if (!is_dir('/var/log/pmss')) {
        @mkdir('/var/log/pmss', 0755, true);
    }
    @file_put_contents('/etc/seedbox/config/fireqos.conf', $config);
    $hasFireqos = trim((string)@shell_exec('command -v fireqos 2>/dev/null')) !== '';
    if ($hasFireqos) {
        @shell_exec('fireqos start /etc/seedbox/config/fireqos.conf >> /var/log/pmss/fireqos.log 2>&1');
    }
}
