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
    $customDir = "/home/{$thisUser}/.lighttpd/custom.d";

    // Rclone port
    $rclonePort = (int) trim(@file_get_contents("/home/{$thisUser}/.rclonePort"));
    if ($rclonePort < 1024 or $rclonePort > 65500) {
        $rclonePort = (int) round(rand(1500, 65500));
        file_put_contents("/home/{$thisUser}/.rclonePort", $rclonePort);
    }

    // qBittorrent port
    $qbittorrentPort = (int) trim(@file_get_contents("/home/{$thisUser}/.qbittorrentPort"));
    if ($qbittorrentPort < 1024 or $qbittorrentPort > 65500) {
        $qbittorrentPort = (int) round(rand(1500, 65500));
        file_put_contents("/home/{$thisUser}/.qbittorrentPort", $qbittorrentPort);
    }

    // PMSS-managed proxy fragments under ~/.lighttpd/custom.d/
    $rcloneConfPath = "{$customDir}/pmss-rclone.conf";
    if (!pmssWriteUserFile($rcloneConfPath, pmssRcloneLighttpdProxyFragment($thisUser, $rclonePort), $thisUser, 0640)) {
        fwrite(STDERR, "[user:{$thisUser}] Failed to write rclone lighttpd fragment\n");
    }

    $qbittorrentConfPath = "{$customDir}/pmss-qbittorrent.conf";
    if (!pmssWriteUserFile($qbittorrentConfPath, pmssQbittorrentLighttpdProxyFragment($thisUser, $qbittorrentPort), $thisUser, 0640)) {
        fwrite(STDERR, "[user:{$thisUser}] Failed to write qbittorrent lighttpd fragment\n");
    }

    // Deluge: generate a per-user proxy fragment under ~/.lighttpd/custom.d/
    // so nginx stays a lightweight reverse proxy.
    $delugeWebPort = null;
    $delugeWebConfPath = "/home/{$thisUser}/.config/deluge/web.conf";
    $delugeParsed = null;
    if (is_readable($delugeWebConfPath)) {
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
                if (pmssDelugeWriteWebConf($delugeWebConfPath, $delugeParsed['meta'], $delugeParsed['config'], $thisUser)) {
                    // Apply base change on the next cron tick (checkDelugeInstances.php).
                    passthru('killall -u '.escapeshellarg($thisUser).' -TERM deluge-web 2>/dev/null || true');
                }
            }
        }
    }
    if ($delugeWebPort === null) {
        // Fallback (legacy hosts): derive deluge-web port from .delugePort.
        $delugePort = (int) trim(@file_get_contents("/home/{$thisUser}/.delugePort"));
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
        $delugeConfPath = "/home/{$thisUser}/.lighttpd/custom.d/pmss-deluge.conf";
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
    if (!pmssWriteUserFile("/home/{$thisUser}/.lighttpd.conf", $thisUserConfig, $thisUser, 0741)) {
        fwrite(STDERR, "[user:{$thisUser}] Failed to write .lighttpd.conf; skipping user\n");
        return;
    }

    $phpIniPath = "/home/{$thisUser}/.lighttpd/php.ini";
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

