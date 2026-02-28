<?php
/**
 * Per-user lighttpd configuration application helper.
 *
 * Extracted from scripts/util/userConfigLighttpd.php to keep the entrypoint
 * small while preserving output, ordering, and on-disk effects.
 *
 * @license GPL-3.0-only
 */

function pmssUserConfigLighttpdConfigureUser(
    string $thisUser,
    string $portsDirectory,
    $template,
    bool $deflateEnabled,
    array $policyDefaults
): void {
    if (!pmssValidateUsername($thisUser)) {
        fwrite(STDERR, "Skipping invalid username: ".substr($thisUser, 0, 20)."\n");
        return;
    }

    $homeDir = "/home/{$thisUser}";
    if (!pmssShouldConfigureLighttpdForHome($homeDir)) {
        return;
    }

    $portFile = "{$portsDirectory}/lighttpd-{$thisUser}";
    if (file_exists($portFile)) {
        $serverPort = (int) file_get_contents($portFile);
    } else {
        // Allocate a unique port using portManager utility
        $serverPort = (int) trim((string)shell_exec('/scripts/util/portManager.php assign '.escapeshellarg($thisUser).' lighttpd'));
    }

    // Prepare directories and defaults.
    if (!pmssPrepareLighttpdUserDirectories($thisUser, $homeDir, $deflateEnabled)) {
        fwrite(STDERR, "[user:{$thisUser}] lighttpd directory preparation failed; skipping\n");
        return;
    }
    pmssEnsureWebdavLockDatabase($thisUser, $homeDir);
    $customDir = $homeDir.'/.lighttpd/custom.d';

    $proxyPortFiles = [
        'rclone' => $homeDir.'/.rclonePort',
        'qbittorrent' => $homeDir.'/.qbittorrentPort',
    ];
    $proxyPorts = [];
    foreach ($proxyPortFiles as $proxyName => $proxyPortFile) {
        $proxyPort = (int) trim((string) @file_get_contents($proxyPortFile));
        if ($proxyPort < 1024 || $proxyPort > 65500) {
            $proxyPort = (int) round(rand(1500, 65500));
            file_put_contents($proxyPortFile, $proxyPort);
        }
        $proxyPorts[$proxyName] = $proxyPort;
    }
    $rclonePort = $proxyPorts['rclone'];
    $qbittorrentPort = $proxyPorts['qbittorrent'];

    // PMSS-managed proxy fragments under ~/.lighttpd/custom.d/
    $proxyFragments = [
        'rclone' => pmssRcloneLighttpdProxyFragment($thisUser, $rclonePort),
        'qbittorrent' => pmssQbittorrentLighttpdProxyFragment($thisUser, $qbittorrentPort),
    ];
    foreach ($proxyFragments as $proxyName => $proxyFragment) {
        $proxyConfPath = "{$customDir}/pmss-{$proxyName}.conf";
        if (!pmssWriteUserFile($proxyConfPath, $proxyFragment, $thisUser, 0640)) {
            fwrite(STDERR, "[user:{$thisUser}] Failed to write {$proxyName} lighttpd fragment\n");
        }
    }

    // Deluge: generate a per-user proxy fragment under ~/.lighttpd/custom.d/
    // so nginx stays a lightweight reverse proxy.
    $delugeWebPort = null;
    $delugeWebConfPath = $homeDir.'/.config/deluge/web.conf';
    $delugeParsed = null;
    if (is_readable($delugeWebConfPath)) {
        $delugeRaw = @file_get_contents($delugeWebConfPath);
        $needsDelugeWebConfWrite = is_string($delugeRaw) && pmssDelugeSessionsListDetected($delugeRaw);
        $delugeParsed = pmssDelugeReadWebConf($delugeWebConfPath);
        if (is_array($delugeParsed) && isset($delugeParsed['config'], $delugeParsed['meta']) && is_array($delugeParsed['config']) && is_array($delugeParsed['meta'])) {
            $port = $delugeParsed['config']['port'] ?? null;
            if (is_int($port) && $port >= 1024 && $port <= 65535) {
                $delugeWebPort = $port;
            }

            $expectedBase = "/user-{$thisUser}/deluge/";
            $expectedBaseNoSlash = "/user-{$thisUser}/deluge";
            $legacyBase = "/deluge-{$thisUser}/";
            $legacyBaseNoSlash = "/deluge-{$thisUser}";
            $base = $delugeParsed['config']['base'] ?? null;
            if (is_string($base) && ($base === $legacyBase || $base === $legacyBaseNoSlash || $base === $expectedBaseNoSlash) && $base !== $expectedBase) {
                $delugeParsed['config']['base'] = $expectedBase;
                $needsDelugeWebConfWrite = true;
            }

            if ($needsDelugeWebConfWrite) {
                // Deluge expects sessions as a dict/object; writing an empty PHP array
                // would serialize as [] and break login session creation.
                pmssDelugeNormalizeEmptySessionsObject($delugeParsed['config']);
                if (pmssDelugeWriteWebConf($delugeWebConfPath, $delugeParsed['meta'], $delugeParsed['config'], $thisUser)) {
                    // Apply web.conf changes on the next cron tick (checkDelugeInstances.php).
                    passthru('killall -u '.escapeshellarg($thisUser).' -TERM deluge-web 2>/dev/null || true');
                }
            }
        }
    }
    if ($delugeWebPort === null) {
        // Fallback (legacy hosts): derive deluge-web port from .delugePort.
        $delugePort = (int) trim((string) @file_get_contents($homeDir.'/.delugePort'));
        if ($delugePort >= 1024 && $delugePort <= 65535) {
            $candidatePorts = [$delugePort + 1, $delugePort];
            foreach ($candidatePorts as $candidate) {
                if ($candidate < 1024 || $candidate > 65535) {
                    continue;
                }
                $sock = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.2);
                if (is_resource($sock)) {
                    fclose($sock);
                    $delugeWebPort = $candidate;
                    break;
                }
            }
            if ($delugeWebPort === null) {
                $candidate = $delugePort + 1;
                $delugeWebPort = ($candidate >= 1024 && $candidate <= 65535) ? $candidate : $delugePort;
            }
        }
    }
    if ($delugeWebPort !== null) {
        $delugeConfPath = $customDir.'/pmss-deluge.conf';
        if (!pmssWriteUserFile($delugeConfPath, pmssDelugeLighttpdProxyFragment($thisUser, $delugeWebPort), $thisUser, 0640)) {
            fwrite(STDERR, "[user:{$thisUser}] Failed to write deluge lighttpd fragment\n");
        }
    }

    $resources = pmssResolveUserResources($thisUser, $policyDefaults);
    $thisUserConfig = pmssRenderLighttpdConfig(
        $template,
        $thisUser,
        $serverPort,
        $rclonePort,
        $qbittorrentPort,
        $resources
    );
    if (!pmssWriteUserFile($homeDir.'/.lighttpd.conf', $thisUserConfig, $thisUser, 0741)) {
        fwrite(STDERR, "[user:{$thisUser}] Failed to write .lighttpd.conf; skipping user\n");
        return;
    }

    $phpIniPath = $homeDir.'/.lighttpd/php.ini';
    if (is_link($phpIniPath) || (file_exists($phpIniPath) && !is_file($phpIniPath))) {
        fwrite(STDERR, "[user:{$thisUser}] Refusing to operate on unsafe php.ini path; skipping user\n");
        return;
    }
    if (!file_exists($phpIniPath)) {
        $skelPhpIni = @file_get_contents('/etc/skel/.lighttpd/php.ini');
        if (!is_string($skelPhpIni) || !pmssWriteUserFile($phpIniPath, $skelPhpIni, $thisUser, 0751)) {
            fwrite(STDERR, "[user:{$thisUser}] Failed to seed php.ini; skipping user\n");
            return;
        }
    }
    pmssUpdatePhpIni($phpIniPath, $resources['memoryLimit']);
    @chmod($phpIniPath, 0751);
    if (pmssRunningAsRoot()) {
        @chown($phpIniPath, $thisUser);
        @chgrp($phpIniPath, $thisUser);
    }

    echo sprintf(
        "[user:%s] lighttpd configured (port=%d, php_memory=%dM, max-procs=%d, children=%d, cpu-quota=%d%%)\n",
        $thisUser,
        $serverPort,
        $resources['memoryLimit'],
        $resources['maxProcs'],
        $resources['children'],
        $resources['cpuQuotaPercent']
    );
}
