<?php
/**
 * Update app installer: deluge.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// #TODO Refactor this installer to use virtualenv instead of system-wide pip. (GH #125)
// #TODO Pin Python package versions explicitly; avoid unbounded upgrades. (GH #125)
// #TODO Replace passthru/backticks with runStep wrappers for consistent logging. (GH #125)
require_once __DIR__.'/remoteBinary.php';

/**
 * Legacy Debian 10 dependency set for the Deluge 2.0.5 source build.
 *
 * Keep this list stable and avoid blanket upgrades to reduce unexpected
 * global Python state drift on long-lived hosts.
 */
function pmssDelugeLegacyPipDependencyPackages(): array
{
    return [
        'twisted[tls]',
        'chardet',
        'mako',
        'pyxdg',
        'pillow',
        'slimit',
        'pygame',
        'certifi',
        'pyasn1==0.4.6',
    ];
}

/**
 * Read a candidate patch target while refusing symlinked or unreadable paths.
 */
function pmssDelugeReadPatchLines(string $path, callable $log, string $readWarning): ?array
{
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        return null;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        $log($readWarning.$path);
        return null;
    }

    return $lines;
}

/**
 * Search a bounded line window without open-coding patch-specific loops.
 */
function pmssDelugeLineSearch(array $lines, string $needle, int $start = 0, ?int $end = null, int $step = 1, bool $regex = false): ?int
{
    if ($lines === [] || $step === 0) {
        return null;
    }

    $lastIndex = count($lines) - 1;
    $start = max(0, min($start, $lastIndex));
    $end = $end === null ? ($step > 0 ? $lastIndex : 0) : max(0, min($end, $lastIndex));

    for ($i = $start; $step > 0 ? $i <= $end : $i >= $end; $i += $step) {
        if ($regex ? preg_match($needle, $lines[$i]) === 1 : strpos($lines[$i], $needle) !== false) {
            return $i;
        }
    }

    return null;
}

/**
 * Persist patched lines with the same newline and dry-run contract.
 */
function pmssDelugeWritePatchedLines(string $path, array $lines, bool $dryRun, callable $log, string $dryRunMessage, string $writeWarning): bool
{
    $newContent = implode("\n", $lines);
    if ($newContent !== '' && substr($newContent, -1) !== "\n") {
        $newContent .= "\n";
    }

    if ($dryRun) {
        $log($dryRunMessage.$path);
        return true;
    }

    if (@file_put_contents($path, $newContent) === false) {
        $log($writeWarning.$path);
        return false;
    }

    return true;
}

/**
 * Patch Deluge cache hit ratio handling for libtorrent 2.0+ stats removal.
 */
function pmssPatchDelugeCacheHitRatio(string $path, bool $dryRun, callable $log): bool
{
    $lines = pmssDelugeReadPatchLines($path, $log, '[WARN] Unable to read Deluge core.py for patching: ');
    if ($lines === null) {
        return false;
    }

    $lineCount = count($lines);
    $hitLine = pmssDelugeLineSearch($lines, 'disk.num_blocks_cache_hits');
    if ($hitLine === null) {
        return false;
    }

    $windowStart = max(0, $hitLine - 6);
    $windowEnd = min($lineCount - 1, $hitLine + 6);
    if (pmssDelugeLineSearch($lines, 'except KeyError', $windowStart, $windowEnd) !== null) {
        return true;
    }

    $ifLine = pmssDelugeLineSearch($lines, '/^\\s*if blocks_read:\\s*$/', $hitLine, 0, -1, true);
    if ($ifLine === null) {
        $log('[WARN] Unable to locate Deluge cache ratio block in '.$path);
        return false;
    }

    $elseLine = pmssDelugeLineSearch($lines, '/^\\s*else:\\s*$/', $hitLine, min($lineCount - 1, $ifLine + 19), 1, true);
    if ($elseLine === null) {
        $log('[WARN] Unable to locate Deluge cache ratio else block in '.$path);
        return false;
    }

    $assignLine = pmssDelugeLineSearch($lines, "self.session_status['read_hit_ratio']", $ifLine + 1, $elseLine - 1);
    $calcLine = pmssDelugeLineSearch($lines, 'disk.num_blocks_cache_hits', $ifLine + 1, $elseLine - 1);
    if ($assignLine === null || $calcLine === null) {
        $log('[WARN] Unable to locate Deluge cache ratio assignment in '.$path);
        return false;
    }

    $elseAssignLine = pmssDelugeLineSearch($lines, "self.session_status['read_hit_ratio']", $elseLine + 1, min($lineCount - 1, $elseLine + 5));
    if ($elseAssignLine === null) {
        $log('[WARN] Unable to locate Deluge cache ratio else assignment in '.$path);
        return false;
    }

    $ifIndent = preg_replace('/^(\\s*).*$/', '$1', $lines[$ifLine]);
    $assignIndent = preg_replace('/^(\\s*).*$/', '$1', $lines[$assignLine]);
    $calcIndent = preg_replace('/^(\\s*).*$/', '$1', $lines[$calcLine]);
    $indentUnit = '';
    if (strpos($calcIndent, $assignIndent) === 0) {
        $indentUnit = substr($calcIndent, strlen($assignIndent));
    }
    if ($indentUnit === '' && strpos($assignIndent, $ifIndent) === 0) {
        $indentUnit = substr($assignIndent, strlen($ifIndent));
    }
    if ($indentUnit === '') {
        $indentUnit = '    ';
    }

    $tryIndent = $assignIndent;
    $innerIndent = $assignIndent.$indentUnit;
    $exprIndent = $innerIndent.$indentUnit;

    $newBlock = [
        $ifIndent.'if blocks_read:',
        $tryIndent.'try:',
        $innerIndent."self.session_status['read_hit_ratio'] = (",
        $exprIndent."self.session_status['disk.num_blocks_cache_hits'] / blocks_read",
        $innerIndent.')',
        $tryIndent.'except KeyError:',
        $innerIndent."self.session_status['read_hit_ratio'] = 0.0",
        $ifIndent.'else:',
        $assignIndent."self.session_status['read_hit_ratio'] = 0.0",
    ];

    array_splice($lines, $ifLine, $elseAssignLine - $ifLine + 1, $newBlock);
    return pmssDelugeWritePatchedLines(
        $path,
        $lines,
        $dryRun,
        $log,
        '[DRYRUN] Would patch Deluge cache hit ratio in ',
        '[WARN] Failed to write Deluge cache ratio patch to '
    );
}

/**
 * Patch Deluge's custom logger override for Python 3.11+ compatibility.
 */
function pmssPatchDelugeFindCallerSignature(string $path, bool $dryRun, callable $log): bool
{
    $lines = pmssDelugeReadPatchLines($path, $log, '[WARN] Unable to read Deluge log.py for patching: ');
    if ($lines === null) {
        return false;
    }

    $searchStart = 0;
    while (($i = pmssDelugeLineSearch($lines, 'def findCaller(', $searchStart)) !== null) {
        if (strpos($lines[$i], 'stacklevel=') !== false) {
            return true;
        }

        if (!preg_match('/^(\s*def\s+findCaller\(\s*self\s*,\s*stack_info\s*=\s*False)\)(.*)$/', $lines[$i], $matches)) {
            $searchStart = $i + 1;
            continue;
        }

        $lines[$i] = $matches[1].', stacklevel=1)'.$matches[2];
        return pmssDelugeWritePatchedLines(
            $path,
            $lines,
            $dryRun,
            $log,
            '[DRYRUN] Would patch Deluge findCaller signature in ',
            '[WARN] Failed to write Deluge findCaller patch to '
        );
    }

    return false;
}

/**
 * Keep Deluge command resolution anchored to distro package binaries.
 *
 * Legacy Debian 10 pip installs may leave direct binaries in /usr/local/bin.
 * On Debian 11+, those stale binaries shadow /usr/bin on PATH and keep hosts
 * pinned to old versions. This helper converges /usr/local/bin/$command to a
 * symlink targeting /usr/bin/$command whenever package-managed binaries exist.
 */
function pmssEnsureDelugeCommandSymlink(string $command, string $systemPath, string $localPath, bool $dryRun, callable $log): bool
{
    if ($command === '' || $systemPath === '' || $localPath === '') {
        return false;
    }

    if (!is_file($systemPath) || !is_executable($systemPath)) {
        $log('[WARN] Skipping Deluge command link refresh; missing system binary: '.$systemPath);
        return false;
    }

    if (is_link($localPath) && readlink($localPath) === $systemPath) {
        return true;
    }

    if (file_exists($localPath) && is_dir($localPath)) {
        $log('[WARN] Refusing to replace Deluge command directory: '.$localPath);
        return false;
    }

    if (file_exists($localPath) || is_link($localPath)) {
        if ($dryRun) {
            $log('[DRYRUN] Would replace legacy Deluge command path: '.$localPath);
            return true;
        }
        if (!@unlink($localPath)) {
            $log('[WARN] Failed to remove legacy Deluge command path: '.$localPath);
            return false;
        }
    }

    $localDir = dirname($localPath);
    if (!is_dir($localDir)) {
        if ($dryRun) {
            $log('[DRYRUN] Would create Deluge command directory: '.$localDir);
            return true;
        }
        if (!pmssDirEnsureExists($localDir, 0755)) {
            $log('[WARN] Failed to create Deluge command directory: '.$localDir);
            return false;
        }
    }

    if ($dryRun) {
        $log('[DRYRUN] Would create Deluge command symlink '.$localPath.' -> '.$systemPath);
        return true;
    }

    if (!@symlink($systemPath, $localPath)) {
        $log('[WARN] Failed to create Deluge command symlink '.$localPath.' -> '.$systemPath);
        return false;
    }

    return true;
}

if (pmssEnvFlagEnabled('PMSS_DELUGE_NO_ENTRYPOINT')) {
    return;
}

$delugeTarballUrl = 'https://ftp.osuosl.org/pub/deluge/source/2.0/deluge-2.0.5.tar.xz';
$delugeTarballSha256 = 'c4bd04abfd211b65218be03f3c46d26f44024884de10e01859fb856fdd6f25d8';
$delugeTarballLabel = 'Deluge 2.0.5 source tarball';
$dryRun = pmssEnvFlagEnabled('PMSS_DRY_RUN');
$log = 'logmsg';
if (empty($debianVersion)) $debianVersion = (string) @file_get_contents('/etc/debian_version');

echo "#### Deluge install // update\n";

// Detect currently installed Deluge version if possible.
$currentVersion = '';
$out = pmssAppVersionProbeOutput('deluge-console --version 2>/dev/null');
if (is_string($out) && preg_match('/deluge\s+([0-9.]+)/i', $out, $m)) {
    $currentVersion = $m[1];
}

// Debian 10 uses a pip/build route for v2.0.5; make it idempotent.
$isDebian10 = (substr($debianVersion, 0, 2) === '10');
if ($isDebian10) {
    $targetVersion = '2.0.5';
    if ($currentVersion !== $targetVersion) {
        echo "\t*** Deluge pip install (target {$targetVersion})\n";
        runStep(
            'Installing Deluge pip dependencies (no global upgrades)',
            pmssBuildCommand('pip', array_merge(['install'], pmssDelugeLegacyPipDependencyPackages()))
        );

        $tmp = pmssFetchPinnedRemoteFile($delugeTarballLabel, $delugeTarballUrl, $delugeTarballSha256);
        if ($tmp === null) {
            return;
        }

        runStep('Cleaning previous Deluge source', 'rm -rf /tmp/deluge-2*');
        runStep(
            'Extracting Deluge source',
            'cd /tmp && '.pmssBuildCommand('tar', ['-xvf', $tmp])
        );
        @unlink($tmp);
        runStep('Building Deluge from source', 'cd /tmp/deluge-2.0.5; python3 setup.py build; python setup.py install');
    } else {
        echo "\t*** Deluge already at target version ({$currentVersion}); skipping pip build\n";
    }
} else {
    // For supported releases, always let apt reconcile Deluge package state.
    // This keeps first installs and package upgrades on the same idempotent path.
    $installed = (trim((string) @shell_exec('dpkg -s deluged 2>/dev/null | grep -iE "^Status:.*installed$"')) !== '')
              && (trim((string) @shell_exec('dpkg -s deluge-web 2>/dev/null | grep -iE "^Status:.*installed$"')) !== '');
    runStep(
        $installed ? 'Upgrading Deluge packages' : 'Installing Deluge packages',
        pmssBuildCommand('apt-get', ['install', '-y', 'deluged', 'deluge-web'])
    );
    runStep('Disabling deluged service', 'systemctl disable deluged || true');
}

// Debian 11+ must resolve Deluge commands to package-managed /usr/bin paths.
if (!$isDebian10) {
    foreach ([
        ['deluge-web', '/usr/bin/deluge-web', '/usr/local/bin/deluge-web'],
        ['deluged', '/usr/bin/deluged', '/usr/local/bin/deluged'],
    ] as $commandPaths) {
        pmssEnsureDelugeCommandSymlink($commandPaths[0], $commandPaths[1], $commandPaths[2], $dryRun, $log);
    }
}

$delugeCandidates = static function (array $patterns): array {
    $candidates = [];
    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $match) {
            if ($match !== '' && !in_array($match, $candidates, true)) {
                $candidates[] = $match;
            }
        }
    }
    return $candidates;
};

$ensurePatch = static function (array $patterns, callable $patch, string $message) use ($delugeCandidates, $dryRun, $log): void {
    $patched = false;
    foreach ($delugeCandidates($patterns) as $path) {
        if ($patch($path, $dryRun, $log)) {
            $patched = true;
        }
    }
    if ($patched) {
        echo $message;
    }
};

$ensurePatch([
    '/usr/lib/python3/dist-packages/deluge/core/core.py',
    '/usr/lib/python3*/dist-packages/deluge/core/core.py',
    '/usr/local/lib/python3*/dist-packages/deluge/core/core.py',
], 'pmssPatchDelugeCacheHitRatio', "\t*** Deluge cache ratio guard ensured\n");
$ensurePatch([
    '/usr/lib/python3/dist-packages/deluge/log.py',
    '/usr/lib/python3*/dist-packages/deluge/log.py',
    '/usr/local/lib/python3*/dist-packages/deluge/log.py',
], 'pmssPatchDelugeFindCallerSignature', "\t*** Deluge Python 3.11 findCaller compatibility ensured\n");
