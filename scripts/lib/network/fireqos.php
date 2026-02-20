<?php
/**
 * FireQOS configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
function networkBuildFireqosConfig(array $networkConfig, array $users, array $localnets): string
{
    $templatePath = getenv('PMSS_FIREQOS_TEMPLATE') ?: '/etc/seedbox/config/template.fireqos';
    $template = file_get_contents($templatePath);
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
    if (!empty($users)) {
        foreach ($users as $username) {
            $uid = trim((string)shell_exec("id -u {$username}"));
            if ($uid === '') {
                continue;
            }

            $limit = '';
            if (is_file($limitStateDir."/{$username}.enabled")) {
                $capMbit = $defaultCapMbit;
                $throttlePath = $homeDir."/{$username}/.throttle";
                if (is_file($throttlePath) && !is_link($throttlePath)) {
                    $raw = trim((string) @file_get_contents($throttlePath));
                    if ($raw !== '' && is_numeric($raw)) {
                        $stored = (int) $raw;
                        if ($stored > 0) {
                            $capMbit = $stored;
                        }
                    }
                }
                $limit = ' ceil '.$capMbit.'Mbit';
            }

            $fireqosConfigUsers .= "    class {$username}{$limit} \n";
            $fireqosConfigUsers .= "      match rawmark {$fireqosMark}\n";
            ++$fireqosMark;
        }
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
