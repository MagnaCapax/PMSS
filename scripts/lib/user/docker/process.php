<?php
/** Docker process observations, bounded diagnostics and UID-checked signals. Loaded by cli.php.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Collect running rootless Docker-related PIDs for a user.
 */
function userDockerCollectPids(string $user, bool $debug = false, ?bool &$checkOk = null): array
{
    $pids = [];
    $checks = [
        ['label' => 'dockerd-rootless.sh', 'pattern' => 'dockerd-rootless\\.sh', 'options' => ['full' => true, 'captureStderr' => true]],
        ['label' => 'dockerd-rootless', 'pattern' => 'dockerd-rootless', 'options' => ['full' => true, 'captureStderr' => true]],
        ['label' => 'dockerd', 'pattern' => 'dockerd', 'options' => ['exact' => true, 'captureStderr' => true]],
    ];
    $checkOkLocal = true;

    foreach ($checks as $check) {
        $out = []; $rc = 0;
        foreach (pmssUserWatchdogProcessPids($user, (string) $check['pattern'], (array) $check['options'], $rc, $out) as $pid) {
            $pids[$pid] = true;
        }
        if ($rc === 127) {
            $checkOkLocal = false;
            break;
        }
        if ($debug) {
            pmssUserLog($user, sprintf('userDocker: %s pgrep rc=%d out=%s', $check['label'], $rc, userDockerProcessDebugOutput($out)));
        }
        if ($rc !== 0 && $rc !== 1) {
            pmssUserLog($user, sprintf('[WARN] Failed to check %s process state (rc=%d): %s', $check['label'], $rc, implode(' | ', $out)));
        }
    }

    if (!$checkOkLocal) {
        $out = [];
        $rc = 0;
        $cmd = sprintf('ps -u %s -o pid=,cmd= 2>&1', escapeshellarg($user));
        exec($cmd, $out, $rc);
        if ($rc === 0) {
            $checkOkLocal = true;
            foreach (pmssConfigRows($out, 2, [], 2) as [$pid, $cmdline]) {
                if (!ctype_digit($pid)) {
                    continue;
                }
                if (stripos($cmdline, 'dockerd') !== false) {
                    $pids[(int) $pid] = true;
                }
            }
            if ($debug) {
                pmssUserLog($user, sprintf('userDocker: ps fallback rc=%d out=%s', $rc, userDockerProcessDebugOutput($out)));
            }
        } else {
            $checkOkLocal = false;
            pmssUserLog($user, sprintf('[WARN] Failed to check Docker process state via ps (rc=%d): %s', $rc, implode(' | ', $out)));
        }
    }

    $checkOk = $checkOkLocal;
    return array_keys($pids);
}

/** Signal resolved PIDs only while their proc entry still belongs to the account. */
function userDockerStopSignal(string $user, int $uid, array $pids, int $signal, string $step): void
{
    $failed = 0;
    foreach (array_unique($pids) as $pid) {
        if (@fileowner('/proc/'.$pid) !== $uid || !@posix_kill($pid, $signal)) $failed++;
    }
    pmssUserLog($user, sprintf('userDocker: step=%s rc=%d count=%d', $step, $failed === 0 ? 0 : 1, count($pids)));
}

/** Render bounded pgrep and ps diagnostics through the same byte-preserving formatter. */
function userDockerProcessDebugOutput(array $out): string
{
    $joined = implode(' | ', $out);
    return $joined === '' ? '(empty)' : (strlen($joined) > 300 ? substr($joined, 0, 300).'...' : $joined);
}
