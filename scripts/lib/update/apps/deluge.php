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
