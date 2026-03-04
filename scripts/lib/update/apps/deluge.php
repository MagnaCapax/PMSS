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
require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';

/**
 * Patch Deluge cache hit ratio handling for libtorrent 2.0+ stats removal.
 */
function pmssPatchDelugeCacheHitRatio(string $path, bool $dryRun, callable $log): bool
{
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        return false;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        $log('[WARN] Unable to read Deluge core.py for patching: '.$path);
        return false;
    }

    $lineCount = count($lines);
    $hitLine = null;
    for ($i = 0; $i < $lineCount; $i++) {
        if (strpos($lines[$i], 'disk.num_blocks_cache_hits') !== false) {
            $hitLine = $i;
            break;
        }
    }
    if ($hitLine === null) {
        return false;
    }

    $windowStart = max(0, $hitLine - 6);
    $windowEnd = min($lineCount - 1, $hitLine + 6);
    for ($i = $windowStart; $i <= $windowEnd; $i++) {
        if (strpos($lines[$i], 'except KeyError') !== false) {
            return true;
        }
    }

    $ifLine = null;
    for ($i = $hitLine; $i >= 0; $i--) {
        if (preg_match('/^\\s*if blocks_read:\\s*$/', $lines[$i])) {
            $ifLine = $i;
            break;
        }
    }
    if ($ifLine === null) {
        $log('[WARN] Unable to locate Deluge cache ratio block in '.$path);
        return false;
    }

    $elseLine = null;
    $scanLimit = min($lineCount, $ifLine + 20);
    for ($i = $hitLine; $i < $scanLimit; $i++) {
        if (preg_match('/^\\s*else:\\s*$/', $lines[$i])) {
            $elseLine = $i;
            break;
        }
    }
    if ($elseLine === null) {
        $log('[WARN] Unable to locate Deluge cache ratio else block in '.$path);
        return false;
    }

    $assignLine = null;
    $calcLine = null;
    for ($i = $ifLine + 1; $i < $elseLine; $i++) {
        if ($assignLine === null && strpos($lines[$i], "self.session_status['read_hit_ratio']") !== false) {
            $assignLine = $i;
        }
        if ($calcLine === null && strpos($lines[$i], 'disk.num_blocks_cache_hits') !== false) {
            $calcLine = $i;
        }
    }
    if ($assignLine === null || $calcLine === null) {
        $log('[WARN] Unable to locate Deluge cache ratio assignment in '.$path);
        return false;
    }

    $elseAssignLine = null;
    $elseScanLimit = min($lineCount, $elseLine + 6);
    for ($i = $elseLine + 1; $i < $elseScanLimit; $i++) {
        if (strpos($lines[$i], "self.session_status['read_hit_ratio']") !== false) {
            $elseAssignLine = $i;
            break;
        }
    }
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
    $newContent = implode("\n", $lines);
    if ($newContent !== '' && substr($newContent, -1) !== "\n") {
        $newContent .= "\n";
    }

    if ($dryRun) {
        $log('[DRYRUN] Would patch Deluge cache hit ratio in '.$path);
        return true;
    }

    if (@file_put_contents($path, $newContent) === false) {
        $log('[WARN] Failed to write Deluge cache ratio patch to '.$path);
        return false;
    }

    return true;
}

function pmssFindDelugeCoreCandidates(): array
{
    $candidates = [];
    $patterns = [
        '/usr/lib/python3/dist-packages/deluge/core/core.py',
        '/usr/lib/python3*/dist-packages/deluge/core/core.py',
        '/usr/local/lib/python3*/dist-packages/deluge/core/core.py',
    ];
    foreach ($patterns as $pattern) {
        $matches = glob($pattern);
        if (!is_array($matches)) {
            continue;
        }
        foreach ($matches as $match) {
            if ($match !== '' && !in_array($match, $candidates, true)) {
                $candidates[] = $match;
            }
        }
    }
    return $candidates;
}

/**
 * Patch Deluge's custom logger override for Python 3.11+ compatibility.
 */
function pmssPatchDelugeFindCallerSignature(string $path, bool $dryRun, callable $log): bool
{
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        return false;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        $log('[WARN] Unable to read Deluge log.py for patching: '.$path);
        return false;
    }

    $lineCount = count($lines);
    for ($i = 0; $i < $lineCount; $i++) {
        if (strpos($lines[$i], 'def findCaller(') === false) {
            continue;
        }

        if (strpos($lines[$i], 'stacklevel=') !== false) {
            return true;
        }

        if (!preg_match('/^(\s*def\s+findCaller\(\s*self\s*,\s*stack_info\s*=\s*False)\)(.*)$/', $lines[$i], $matches)) {
            continue;
        }

        $lines[$i] = $matches[1].', stacklevel=1)'.$matches[2];
        $newContent = implode("\n", $lines);
        if ($newContent !== '' && substr($newContent, -1) !== "\n") {
            $newContent .= "\n";
        }

        if ($dryRun) {
            $log('[DRYRUN] Would patch Deluge findCaller signature in '.$path);
            return true;
        }

        if (@file_put_contents($path, $newContent) === false) {
            $log('[WARN] Failed to write Deluge findCaller patch to '.$path);
            return false;
        }

        return true;
    }

    return false;
}

/**
 * Locate candidate Deluge log.py files for compatibility patching.
 */
function pmssFindDelugeLogCandidates(): array
{
    $candidates = [];
    $patterns = [
        '/usr/lib/python3/dist-packages/deluge/log.py',
        '/usr/lib/python3*/dist-packages/deluge/log.py',
        '/usr/local/lib/python3*/dist-packages/deluge/log.py',
    ];
    foreach ($patterns as $pattern) {
        $matches = glob($pattern);
        if (!is_array($matches)) {
            continue;
        }
        foreach ($matches as $match) {
            if ($match !== '' && !in_array($match, $candidates, true)) {
                $candidates[] = $match;
            }
        }
    }
    return $candidates;
}

if (getenv('PMSS_DELUGE_NO_ENTRYPOINT') === '1') {
    return;
}

$delugeTarballUrl = 'https://ftp.osuosl.org/pub/deluge/source/2.0/deluge-2.0.5.tar.xz';
$delugeTarballSha256 = 'c4bd04abfd211b65218be03f3c46d26f44024884de10e01859fb856fdd6f25d8';
$delugeTarballLabel = 'Deluge 2.0.5 source tarball';
$dryRun = getenv('PMSS_DRY_RUN') === '1';
$log = function_exists('logmsg') ? 'logmsg' : 'logMessage';
if (empty($debianVersion)) $debianVersion = (string) @file_get_contents('/etc/debian_version');

echo "#### Deluge install // update\n";

// Detect currently installed Deluge version if possible.
$currentVersion = '';
$out = @shell_exec('deluge-console --version 2>/dev/null');
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
            'Installing Deluge pip dependencies',
            pmssBuildCommand('pip', [
                'install',
                '--upgrade',
                'twisted[tls]',
                'chardet',
                'mako',
                'pyxdg',
                'pillow',
                'slimit',
                'pygame',
                'certifi',
                'pyasn1==0.4.6',
            ])
        );
        runStep(
            'Ensuring Deluge pillow dependency',
            pmssBuildCommand('pip', ['install', '--upgrade', 'pillow'])
        );

        $tmp = tempnam(sys_get_temp_dir(), 'pmss-deluge-');
        if ($tmp === false || $tmp === '') {
            $log('[WARN] Unable to create temp file for Deluge source download');
            return;
        }

        $downloadCmd = pmssBuildCommand('wget', ['-q', '-O', $tmp, $delugeTarballUrl]);
        $rc = runStep("Downloading {$delugeTarballLabel}", $downloadCmd);
        if ($rc !== 0) {
            @unlink($tmp);
            return;
        }

        if ($dryRun) {
            @unlink($tmp);
            return;
        }

        $actualSha = @hash_file('sha256', $tmp);
        if (!is_string($actualSha) || strtolower($actualSha) !== strtolower($delugeTarballSha256)) {
            $log("[WARN] Checksum mismatch for {$delugeTarballLabel}; aborting");
            @unlink($tmp);
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
    // For supported releases, prefer apt but only when not installed.
    $installed = (trim((string) @shell_exec('dpkg -s deluged 2>/dev/null | grep -iE "^Status:.*installed$"')) !== '')
              && (trim((string) @shell_exec('dpkg -s deluge-web 2>/dev/null | grep -iE "^Status:.*installed$"')) !== '');
    if (!$installed) {
        runStep(
            'Installing Deluge packages',
            pmssBuildCommand('apt-get', ['install', '-y', 'deluged', 'deluge-web'])
        );
        runStep('Disabling deluged service', 'systemctl disable deluged || true');
    } else {
        echo "\t*** Deluge packages already installed; skipping apt install\n";
    }
}

// Ensure convenience symlinks exist only once.
if (file_exists('/usr/bin/deluged') && !file_exists('/usr/local/bin/deluged')) {
    runStep(
        'Creating Deluge convenience symlinks',
        'ln -s /usr/bin/deluge-web /usr/local/bin/deluge-web; ln -s /usr/bin/deluged /usr/local/bin/deluged'
    );
}

$patchCandidates = pmssFindDelugeCoreCandidates();
if (!empty($patchCandidates)) {
    $patched = false;
    foreach ($patchCandidates as $path) {
        if (pmssPatchDelugeCacheHitRatio($path, $dryRun, $log)) {
            $patched = true;
        }
    }
    if ($patched) {
        echo "\t*** Deluge cache ratio guard ensured\n";
    }
}

$logPatchCandidates = pmssFindDelugeLogCandidates();
if (!empty($logPatchCandidates)) {
    $patched = false;
    foreach ($logPatchCandidates as $path) {
        if (pmssPatchDelugeFindCallerSignature($path, $dryRun, $log)) {
            $patched = true;
        }
    }
    if ($patched) {
        echo "\t*** Deluge Python 3.11 findCaller compatibility ensured\n";
    }
}
