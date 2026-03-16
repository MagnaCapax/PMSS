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

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(pmssUserTorrentsMain($argv));
}

function pmssUserTorrentsMain(array $argv): int
{
    // Options.
    $options = getopt('', ['by-client', 'help']);
    if (isset($options['help'])) {
        pmssUserTorrentsPrintHelp();
        return 0;
    }
    $byClient = isset($options['by-client']);
    $homeDir = rtrim(getenv('PMSS_HOME_DIR') ?: '/home', '/');

    // Get & parse users list.
    $lines = [];
    $rc = 0;
    exec(escapeshellarg(__DIR__.'/listUsers.php'), $lines, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "Error: listUsers.php failed; aborting.\n");
        return 1;
    }
    $users = array_filter(array_map('trim', $lines), 'strlen');

    foreach($users AS $thisUser) {    // Loop users checking their instances
        if (!pmssValidateUsername($thisUser)) {
            pmssUserWriteLogs(
                pmssUserBaseContext(
                    'torrents',
                    'validate',
                    $thisUser,
                    [
                        'status'  => 'ERR',
                        'message' => 'Skipping invalid username in userTorrents',
                    ]
                )
            );
            continue;
        }
        $counts = pmssUserTorrentsCountForUser($homeDir, $thisUser);
        if ($byClient) {
            echo "{$thisUser}: total=".number_format($counts['total'])." rtorrent=".number_format($counts['rtorrent'])." deluge=".number_format($counts['deluge'])." qbittorrent=".number_format($counts['qbittorrent'])."\n";
        } else {
            echo "{$thisUser}: " . number_format($counts['total']) . "\n";
        }
    }

    return 0;
}

function pmssUserTorrentsPrintHelp(): void
{
    $self = basename(__FILE__);
    echo <<<TXT
Usage: {$self} [--by-client]

Options:
  --by-client  Show per-client breakdown (rtorrent/deluge/qbittorrent).
  --help       Show this help.

TXT;
    echo PHP_EOL;
}

function pmssUserTorrentsCountForUser(string $homeDir, string $username): array
{
    if (!pmssUsernameIsValid($username)) {
        return ['rtorrent' => 0, 'deluge' => 0, 'qbittorrent' => 0, 'total' => 0];
    }

    $home = $homeDir.'/'.$username;

    $counts = [
        'rtorrent' => pmssUserTorrentsCountUnique([
            $home.'/session/*.torrent',
        ], true),
        'deluge' => pmssUserTorrentsCountUnique([
            $home.'/.config/deluge/state/*.torrent',
            $home.'/.delugeSession/*.torrent',
            $home.'/.sessionDeluge/*.torrent',
        ], true),
        'qbittorrent' => pmssUserTorrentsCountUnique([
            $home.'/.local/share/data/qBittorrent/BT_backup/*.torrent',
            $home.'/.local/share/data/qBittorrent/BT_backup/*.fastresume',
            $home.'/.local/share/qBittorrent/BT_backup/*.torrent',
            $home.'/.local/share/qBittorrent/BT_backup/*.fastresume',
            $home.'/.config/qBittorrent/BT_backup/*.torrent',
            $home.'/.config/qBittorrent/BT_backup/*.fastresume',
        ], true),
    ];
    $counts['total'] = array_sum($counts);

    return $counts;
}

function pmssUserTorrentsCountUnique(array $patterns, bool $stripExtension): int
{
    $seen = [];
    foreach ($patterns as $pattern) {
        $matches = glob($pattern);
        if (!is_array($matches)) {
            continue;
        }
        foreach ($matches as $path) {
            $name = basename($path);
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            if ($stripExtension) {
                $name = pathinfo($name, PATHINFO_FILENAME);
            }
            if ($name === '') {
                continue;
            }
            $seen[$name] = true;
        }
    }
    return count($seen);
}
