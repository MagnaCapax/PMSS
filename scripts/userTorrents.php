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
require_once __DIR__.'/lib/userLifecycle.php';

function pmssUserTorrentsCountForUser(string $homeDir, string $username): array
{
    $counts = ['rtorrent' => 0, 'deluge' => 0, 'qbittorrent' => 0, 'total' => 0];
    if (!pmssUsernameIsValid($username)) {
        return $counts;
    }

    $home = $homeDir.'/'.$username;

    $clientPatterns = [
        'rtorrent' => ['session'],
        'deluge' => ['.config/deluge/state', '.delugeSession', '.sessionDeluge'],
        'qbittorrent' => ['.local/share/data/qBittorrent/BT_backup', '.local/share/qBittorrent/BT_backup', '.config/qBittorrent/BT_backup'],
    ];

    foreach ($clientPatterns as $client => $patterns) {
        $seen = [];
        $suffixes = $client === 'qbittorrent' ? ['/*.torrent', '/*.fastresume'] : ['/*.torrent'];
        foreach ($patterns as $pattern) {
            foreach ($suffixes as $suffix) {
                foreach (glob($home.'/'.$pattern.$suffix) ?: [] as $path) {
                    $name = pathinfo(basename($path), PATHINFO_FILENAME);
                    if ($name !== '' && $name !== '.' && $name !== '..') {
                        $seen[$name] = true;
                    }
                }
            }
        }
        $counts[$client] = count($seen);
    }
    $counts['total'] = array_sum($counts);

    return $counts;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    // Options.
    $options = getopt('', ['by-client', 'help']);
    if (isset($options['help'])) {
        $self = basename(__FILE__);
        echo <<<TXT
Usage: {$self} [--by-client]

Options:
  --by-client  Show per-client breakdown (rtorrent/deluge/qbittorrent).
  --help       Show this help.

TXT;
        echo PHP_EOL;
        exit(0);
    }
    $byClient = isset($options['by-client']);
    $homeDir = rtrim(getenv('PMSS_HOME_DIR') ?: '/home', '/');

    // Get & parse users list.
    $listUsersResult = pmssListManagedUsersResult(__DIR__.'/listUsers.php');
    if (($users = pmssListManagedUsersFromResult($listUsersResult)) === null) {
        exit(1);
    }

    foreach ($users as $thisUser) {
        $counts = pmssUserTorrentsCountForUser($homeDir, $thisUser);
        echo ($byClient
            ? "{$thisUser}: total=".number_format($counts['total'])." rtorrent=".number_format($counts['rtorrent'])." deluge=".number_format($counts['deluge'])." qbittorrent=".number_format($counts['qbittorrent'])
            : "{$thisUser}: ".number_format($counts['total']))."\n";
    }

    exit(0);
}
