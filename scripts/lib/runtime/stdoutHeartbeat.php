<?php
/** Keep a non-interactive caller's stdout readable during blocking operations. */

/** The caller supplies its TTY probe result so the decision stays testable. */
function pmssStdoutHeartbeatShouldStart(bool $stdoutIsTty): bool
{
    return !$stdoutIsTty;
}

/** Apply the shared CLI stdout policy before a blocking lifecycle command. */
function pmssStdoutHeartbeatStartForStdout(): void { if (pmssStdoutHeartbeatShouldStart(pmssStreamIsTty(STDOUT))) pmssStdoutHeartbeatStart(); }

/** Start a child that shares stdout and checks its parent's life each interval. */
function pmssStdoutHeartbeatStart(int $intervalSeconds = 5): ?int
{
    if ($intervalSeconds < 1 || isset($GLOBALS['PMSS_STDOUT_HEARTBEAT_PROCESS'])) {
        return null;
    }

    $parentPid = getmypid();
    $loop = 'while kill -0 '.(int) $parentPid.' 2>/dev/null; do printf "\\n"; sleep '.(int) $intervalSeconds.' 1>/dev/null; done';
    // exec makes proc_get_status() identify the loop itself, not a wrapper shell.
    // fd 1 is inherited by leaving it out: passing STDOUT would seek a redirected log file back.
    $process = @proc_open('exec /bin/sh -c '.escapeshellarg($loop), array(
        0 => array('file', '/dev/null', 'r'),
        2 => array('file', '/dev/null', 'w'),
    ), $pipes);
    if (!is_resource($process)) {
        return null;
    }

    $GLOBALS['PMSS_STDOUT_HEARTBEAT_PROCESS'] = $process;
    register_shutdown_function('pmssStdoutHeartbeatStop');
    $status = proc_get_status($process);
    return $status['pid'];
}

/** Stop and reap the child before returning to the caller or exiting. */
function pmssStdoutHeartbeatStop(): void
{
    $process = $GLOBALS['PMSS_STDOUT_HEARTBEAT_PROCESS'] ?? null;
    unset($GLOBALS['PMSS_STDOUT_HEARTBEAT_PROCESS']);
    if (!is_resource($process)) {
        return;
    }
    @proc_terminate($process);
    @proc_close($process);
}
