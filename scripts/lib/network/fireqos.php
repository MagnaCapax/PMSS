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
    if (!empty($users)) {
        foreach ($users as $username) {
            $uid = trim((string)shell_exec("id -u {$username}"));
            if ($uid === '') {
                continue;
            }

            $limit = '';
            if (file_exists("/var/run/pmss/trafficLimits/{$username}.enabled")) {
                $limit = ' ceil '.((int)$networkConfig['throttle']['max']).'Mbit';
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
