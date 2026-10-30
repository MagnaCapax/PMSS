<?php
/** Checkarray execution, skipped-array cancellation and successful-start state. @license GPL-3.0-only */

/** Best-effort cancellation for a queued/interrupted check on skipped arrays. */
function pmssMdadmCheckarrayRequestIdle(string $array, string $sysBlockRoot): bool
{
    if (!pmssMdadmCheckarrayArrayNameIsSafe($array) || pmssFilesystemPathHasNulByte($sysBlockRoot)) {
        return false;
    }

    $path = rtrim($sysBlockRoot, '/').'/'.$array.'/md/sync_action';
    return file_exists($path) && pmssFileWriteComplete($path, "idle\n");
}

/** Run Debian checkarray and preserve its stdout/stderr in the cron log. */
function pmssMdadmCheckarrayRunCommand(string $binary, array $arrays): int
{
    // Validate the entire request before quoting or launching any command.
    if ($binary === '' || pmssFilesystemPathHasNulByte($binary)) return 1;
    foreach ($arrays as $array) {
        if (!is_string($array) || !pmssMdadmCheckarrayArrayNameIsSafe($array)) return 1;
    }
    // PMSS owns the cron schedule; --cron would let AUTOCHECK silently skip it.
    $args = array_merge(['--idle', '--quiet'], empty($arrays) ? ['--all'] : array_values($arrays));
    $result = pmssCommandCapture(pmssBuildCommand($binary, $args), 120);
    if ((string) ($result['stdout'] ?? '') !== '') {
        echo (string) $result['stdout'];
    }
    if ((string) ($result['stderr'] ?? '') !== '') {
        fwrite(STDERR, (string) $result['stderr']);
    }

    return (int) ($result['rc'] ?? 1);
}

/** A failed state write must not turn a successful checkarray invocation into failure. */
function pmssMdadmCheckarrayRunAndRemember(string $binary, array $arrays, string $statePath): int
{
    $startedAt = time();
    $rc = pmssMdadmCheckarrayRunCommand($binary, $arrays);
    if ($rc === 0 && ($statePath === '' || pmssFilesystemPathHasNulByte($statePath)
        || (!is_dir(dirname($statePath)) && !@mkdir(dirname($statePath), 0755, true))
        || !pmssFileWriteComplete($statePath, (string) $startedAt."\n"))) {
        pmssMdadmCheckarrayLog('unable to write last-check state');
    }
    return $rc;
}

