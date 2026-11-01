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

/** Resolve managed users and trusted throttle caps in their input order. */
function networkFireqosUserCaps(array $networkConfig, array $users): array
{
    $limitStateDir = pmssResolvePathFromEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', '/var/run/pmss/trafficLimits');
    $configuredCapMbit = $networkConfig['throttle']['max'] ?? 100;
    $defaultCapMbit = is_numeric($configuredCapMbit) && (int) $configuredCapMbit > 0
        ? (int) $configuredCapMbit : 100;
    $caps = [];

    foreach ($users as $username) {
        if (!pmssValidateUsername($username)) {
            continue;
        }
        $uid = trim((string) @shell_exec('id -u '.escapeshellarg($username).' 2>/dev/null'));
        $uid = pmssUnsignedDecimalIntParse($uid);
        if ($uid === null) {
            continue;
        }

        $capMbit = null;
        $markerPath = $limitStateDir."/{$username}.enabled";
        if (is_file($markerPath)) {
            // The root runtime marker carries the cap trafficLimits.php chose; an empty marker
            // (written before the cap was recorded there) means the default cap.
            $stored = pmssUnsignedDecimalIntParse(trim((string) pmssReadRegularFileContentsVerified($markerPath, pmssTestModeEnabled() ? null : 0, 32)));
            $capMbit = ($stored !== null && $stored > 0) ? $stored : $defaultCapMbit;
        }
        $caps[$username] = ['uid' => $uid, 'cap' => $capMbit];
    }
    return $caps;
}

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

    $userCaps = networkFireqosUserCaps($networkConfig, $users);
    $fireqosConfigUsers = '';
    foreach ($userCaps as $user) {
        $fireqosConfigUsers .= "      match rawmark {$user['uid']}\n";
    }
    foreach ($userCaps as $username => $user) {
        $limit = $user['cap'] === null ? '' : ' ceil '.$user['cap'].'Mbit';
        $fireqosConfigUsers .= "    class {$username}{$limit}\n";
        $fireqosConfigUsers .= "      match rawmark {$user['uid']}\n";
    }

    return str_replace(
        ['##INTERFACE', '##SPEED', '##LOCALNETWORK', '##USERMATCHES'],
        [
            $networkConfig['interface'] ?? 'eth0',
            $networkConfig['speed'] ?? 1000,
            $fireqosConfigLocal,
            $fireqosConfigUsers
        ],
        $template
    );
}

/** Read the user leaf caps from one rendered users group; null means no group. */
function networkFireqosRenderedUserCaps(string $config): ?array
{
    $inside = false;
    $caps = [];
    foreach (pmssConfigTextLines($config, []) as $line) {
        if (!$inside) {
            if (preg_match('/^class group users\b/', $line)) {
                $inside = true;
            }
            continue;
        }
        if ($line === 'class group end') {
            return $caps;
        }
        if (preg_match('/^class ([a-zA-Z0-9_-]+)(?: ceil ([0-9]+)Mbit)?$/', $line, $match)) {
            $cap = isset($match[2]) ? pmssUnsignedDecimalIntParse($match[2]) : null;
            // An unrepresentable rendered cap is not evidence of convergence.
            if (isset($match[2]) && $cap === null) return null;
            $caps[$match[1]] = $cap;
        }
    }
    return null;
}

/** Compare cap maps without treating account order as policy drift. */
function networkFireqosNeedsApply(array $desired, ?array $rendered): bool
{
    return $rendered === null || $desired != $rendered;
}

function networkApplyFireqos(string $config): bool
{
    $configPath = pmssResolvePathFromEnv('PMSS_FIREQOS_CONFIG_PATH', '/etc/seedbox/config/fireqos.conf');
    $logPath = pmssResolvePathFromEnv('PMSS_FIREQOS_LOG_PATH', '/var/log/pmss/fireqos.log');

    // Ensure the rendered policy is on disk before attempting a restart.
    if (!pmssDirEnsureExists(dirname($configPath), 0755) || !pmssDirEnsureExists(dirname($logPath), 0755)) {
        return false;
    }
    if (!pmssAtomicWriteFile($configPath, $config, 0644)) {
        return false;
    }

    if (pmssCommandPath('fireqos') === '') {
        return false;
    }
    $output = [];
    $rc = 1;
    exec('fireqos start '.escapeshellarg($configPath).' >> '.escapeshellarg($logPath).' 2>&1', $output, $rc);
    if ($rc !== 0) {
        @file_put_contents($logPath, pmssLogFormatTimestampedLine("fireqos start failed (rc={$rc})", 'c', ' '), FILE_APPEND);
        // The hourly converge reads this file as the applied state; keep a failed policy out of that path.
        if (!@rename($configPath, $configPath.'.failed')) {
            @unlink($configPath);
        }
        return false;
    }
    @unlink($configPath.'.failed');
    return true;
}
