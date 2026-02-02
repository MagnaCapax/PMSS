<?php
/**
 * Update app installer: filebot.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Let's install filebot!
// #TODO Replace ad-hoc wget/dpkg flow with a repository/dpkg-baseline driven
//       install. Prefer using runStep() for logging instead of passthru. (GH #133)

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/remoteBinary.php';

$filebotVersion = '4.9.4 (r8736)';
if (file_exists('/usr/bin/filebot')) {
    $out = @shell_exec('filebot -version 2>/dev/null');
    if ($out === null || strpos((string)$out, $filebotVersion) === false) {
        @unlink('/usr/bin/filebot');
    }
}

if (!file_exists('/usr/bin/filebot')) {
    // #TODO Switch to HTTPS and checksum verification if direct download stays. (GH #133)
    // #TODO Refactor to runStep wrappers for consistent JSON logging. (GH #133)
    $filebotUrl = 'https://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb';
    $filebotSha256 = '30d1483a6ec3e24df6f518b6f82e4115140be010026e554c15be9a75b47783cc';
    $filebotLabel = 'FileBot 4.9.4';

    $dryRun = getenv('PMSS_DRY_RUN') === '1';
    $log = function_exists('logmsg') ? 'logmsg' : 'logMessage';

    $tmp = tempnam(sys_get_temp_dir(), 'pmss-filebot-');
    if ($tmp === false || $tmp === '') {
        $log('[WARN] Unable to create temp file for FileBot download');
        return;
    }

    $downloadCmd = pmssBuildCommand('wget', ['-q', '-O', $tmp, $filebotUrl]);
    $rc = runStep("Downloading {$filebotLabel} package", $downloadCmd);
    if ($rc !== 0) {
        @unlink($tmp);
        return;
    }

    if ($dryRun) {
        @unlink($tmp);
        return;
    }

    $actualSha = @hash_file('sha256', $tmp);
    if (!is_string($actualSha) || strtolower($actualSha) !== strtolower($filebotSha256)) {
        $log("[WARN] Checksum mismatch for {$filebotLabel}; aborting");
        @unlink($tmp);
        return;
    }

    $installCmd = pmssBuildCommand('dpkg', ['-i', $tmp]);
    runStep("Installing {$filebotLabel}", $installCmd);
    @unlink($tmp);
}
