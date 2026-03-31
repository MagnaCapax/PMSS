<?php
/**
 * @domain user home maintenance — skeleton, files, permissions
 *
 * User home maintenance helpers for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Apply a text patch to a writable tenant file when it exists.
 *
 * @param string   $path    Writable file path in the tenant home.
 * @param callable $patcher Content transformer that returns updated text.
 */
function pmssUserPatchWritableFile(string $path, callable $patcher): void
{
    if (!is_file($path)
        || is_link($path)
        || !is_string($content = @file_get_contents($path))
        || $content === '') {
        return;
    }

    $updated = $patcher($content);
    if (is_string($updated) && $updated !== $content) {
        @file_put_contents($path, $updated);
    }
}

/** @param array<int, array{legacy:string,patched:string}> $replacements */
function pmssUserPatchWritableStrings(string $path, array $replacements): void
{
    pmssUserPatchWritableFile($path, static function (string $updated) use ($replacements): string {
        foreach ($replacements as $replacement) {
            if (strpos($updated, $replacement['patched']) !== false
                && strpos($updated, $replacement['legacy']) === false) {
                continue;
            }
            $placeholder = '';
            if (strpos($replacement['patched'], $replacement['legacy']) !== false
                && strpos($updated, $replacement['patched']) !== false) {
                $placeholder = '__PMSS_PATCHED_'.sha1($replacement['patched']).'__';
                while (strpos($updated, $placeholder) !== false) {
                    $placeholder .= '_X';
                }
                $updated = str_replace($replacement['patched'], $placeholder, $updated);
            }
            $updated = str_replace($replacement['legacy'], $replacement['patched'], $updated, $count);
            if ($placeholder !== '') {
                $updated = str_replace($placeholder, $replacement['patched'], $updated);
            }
        }
        return $updated;
    });
}

/**
 * Remove a file or symlink if it still exists.
 */
function pmssUserDeletePathIfPresent(string $path): void
{
    if ((is_file($path) || is_link($path)) && file_exists($path)) {
        @unlink($path);
    }
}

function pmssUserApplySkeletonFiles(array $ctx): void
{
    $user = $ctx['user'];
    $legacyDownloadHeaderBlock = <<<'PHP'
    if (strstr($_SERVER['HTTP_USER_AGENT'], "MSIE")) {
        $fileName = preg_replace('/\./', '%2e', $fileName, substr_count($fileName, '.') - 1);
        header("Content-Disposition: $contentDisposition;filename=\"$fileName\"");
    } else {
        header("Content-Disposition: $contentDisposition;filename=\"$fileName\"");
    }
PHP;
    $patchPhpCommand = static function (string $path, string $requireLine, string $legacyCommand, string $patchedCommand): void {
        pmssUserPatchWritableFile($path, static function (string $updated) use ($requireLine, $legacyCommand, $patchedCommand): ?string {
            if (strpos($updated, $requireLine) === false) {
                $updated = preg_replace('/^<\?php\s*/', $requireLine, $updated, 1, $count);
                if (!is_string($updated) || $count !== 1) {
                    return null;
                }
            }

            return strpos($updated, $patchedCommand) !== false ? $updated : str_replace($legacyCommand, $patchedCommand, $updated);
        });
    };

    $files = [
        '.rtorrentExecute.php',
        '.rtorrentRestart.php',
        '.bashrc',
        'install-media-stack.sh',
        'install-ai-tools.sh',
        'bin/docker-install-wireguard.sh',
        'bin/linuxserverInstall.sh',
        'bin/support',
        '.scriptsInc.php',
        '.lighttpd/php.ini',
        'radarr-sonarr.txt',
        'www/deluge.php',
        'www/filemanager.php',
        'www/index.php',
        'www/mediaStack.php',
        'www/openvpn-config.tgz',
        'www/qbittorrent.php',
        'www/rutorrent/js/content.js',
        'www/rutorrent/php/settings.php',
        'www/rutorrent/plugins/theme/conf.php',
        'www/welcome.php',
    ];
    foreach ($files as $file) {
        updateUserFile($file, $user);
    }

    pmssUserDeletePathIfPresent($ctx['home'].'/www/phpXplorer');

    // Remove dead extsearch engines from tenant copies until the frozen
    // skeleton ruTorrent tree can be curated directly.
    foreach ([
        $ctx['home'].'/www/rutorrent/plugins/extsearch/engines/RARbgTorrentAPI.php',
        $ctx['home'].'/www/rutorrent/plugins/extsearch/engines/Demonoid.php',
        $ctx['home'].'/www/rutorrent/plugins/extsearch/engines/KAT.php',
    ] as $path) {
        pmssUserDeletePathIfPresent($path);
    }

    // Patch tenant copies until the frozen skeleton filemanager source can be
    // updated upstream without touching the locked tree.
    pmssUserPatchWritableStrings(
        $ctx['home'].'/www/filemanager.php',
        [
            ['legacy' => '        ob_flush();', 'patched' => '        @ob_flush();'],
            ['legacy' => 'https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.slim.min.js', 'patched' => 'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.slim.min.js'],
            ['legacy' => 'https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js', 'patched' => 'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js'],
            ['legacy' => 'https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js', 'patched' => 'https://cdn.datatables.net/2.0.8/js/dataTables.min.js'],
            ['legacy' => '        str_replace($range, "-", $range);', 'patched' => '        $range = str_replace("-", "", $range);'],
            ['legacy' => $legacyDownloadHeaderBlock, 'patched' => '    header("Content-Disposition: $contentDisposition;filename=\"$fileName\"");'],
        ]
    );

    // Keep tenant torrent frontend copies off the legacy Python port helpers
    // until the frozen /etc/skel/www sources can be updated directly.
    $patchPhpCommand($ctx['home'].'/www/deluge.php', "<?php\nrequire_once '/scripts/lib/user/torrentPort.php';\n", "shell_exec('nohup python3 /home/\$(whoami)/.delugePort.py; deluged -l /home/\$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/\$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &');", "if (function_exists('pmssDelugePortEnsureCurrentUser')) {\n        pmssDelugePortEnsureCurrentUser();\n    }\n    shell_exec('nohup deluged -l /home/\$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/\$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &');");
    $patchPhpCommand($ctx['home'].'/www/qbittorrent.php', "<?php\nrequire_once '/scripts/lib/user/torrentPort.php';\n", "passthru('python3 /home/\$(whoami)/.qbittorrentPort.py; zsh -c \"qbittorrent-nox -d\" >> /dev/null 2>&1 &');", "if (function_exists('pmssQbittorrentPortEnsureCurrentUser')) {\n        pmssQbittorrentPortEnsureCurrentUser();\n    }\n    passthru('zsh -c \"qbittorrent-nox -d\" >> /dev/null 2>&1 &');");

    $skelBase = pmssSkeletonBase();
    foreach (glob($skelBase.'/www/rutorrent/plugins/hddquota/*') ?: [] as $file) {
        $relative = strpos($file, $skelBase.'/') === 0 ? substr($file, strlen($skelBase) + 1) : str_replace('/etc/skel/', '', $file);
        updateUserFile($relative, $user);
    }
}
