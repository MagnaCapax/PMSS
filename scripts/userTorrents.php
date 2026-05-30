#!/usr/bin/env php
<?php
/**
 * Print per-user torrent counts from rTorrent session directories.
 *
 * Intended for quick operational overview of how many torrents each tenant
 * has active by inspecting /home/<user>/session/*.torrent files.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/lib/runtime.php';
require_once __DIR__.'/lib/userLifecycle.php';
require_once __DIR__.'/lib/cli/optionParser.php';

function pmssUserTorrentsCountForUser(string $homeDir, string $username): array
{
    $counts = ['rtorrent' => 0, 'deluge' => 0, 'qbittorrent' => 0, 'total' => 0];
    if (!pmssUsernameIsValid($username)) return $counts;
    $home = $homeDir.'/'.$username;
    $clientPatterns = [
        'rtorrent' => ['session/*.torrent'],
        'deluge' => ['.config/deluge/state/*.torrent', '.delugeSession/*.torrent', '.sessionDeluge/*.torrent'],
        'qbittorrent' => [
            '.local/share/data/qBittorrent/BT_backup/*.torrent', '.local/share/data/qBittorrent/BT_backup/*.fastresume',
            '.local/share/qBittorrent/BT_backup/*.torrent', '.local/share/qBittorrent/BT_backup/*.fastresume',
            '.config/qBittorrent/BT_backup/*.torrent', '.config/qBittorrent/BT_backup/*.fastresume',
        ],
    ];

    foreach ($clientPatterns as $client => $patterns) {
        $seen = [];
        foreach ($patterns as $pattern) {
            foreach (glob($home.'/'.$pattern) ?: [] as $path) {
                $name = pathinfo(basename($path), PATHINFO_FILENAME);
                if ($name !== '' && $name !== '.' && $name !== '..') {
                    $seen[$name] = true;
                }
            }
        }
        $counts[$client] = count($seen);
    }
    $counts['total'] = array_sum($counts);

    return $counts;
}

pmssRunCliEntrypointWithArgv(__FILE__, static function (array $argv): int {
    $self = basename(__FILE__);
    $usage = pmssCliHelpUsageOptions($self.' [--by-client]', [
        ['--by-client', 'Show per-client breakdown (rtorrent/deluge/qbittorrent).'],
        ['--help', 'Show this help.'],
    ], 13);
    if (($parsed = pmssParseCliTokensOrHelp($argv, $usage, [], null)) === null) return 0;
    $byClient = pmssCliOptionPresent($parsed, 'by-client');
    $homeDir = pmssDirPathResolve(null, 'PMSS_HOME_DIR', '/home');

    if (($users = pmssListManagedUsersFromResult(pmssListManagedUsersResult(__DIR__.'/listUsers.php'))) === null) {
        exit(1);
    }

    foreach ($users as $thisUser) {
        $counts = pmssUserTorrentsCountForUser($homeDir, $thisUser);
        echo ($byClient
            ? "{$thisUser}: total=".number_format($counts['total'])." rtorrent=".number_format($counts['rtorrent'])." deluge=".number_format($counts['deluge'])." qbittorrent=".number_format($counts['qbittorrent'])
            : "{$thisUser}: ".number_format($counts['total']))."\n";
    }

    return 0;
});
