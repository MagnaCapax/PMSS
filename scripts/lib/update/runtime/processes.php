<?php
/**
 * Process and service helpers for update flows.
 */

require_once __DIR__.'/commands.php';

/**
 * True when any process with the exact binary name is running.
 */
function pmssProcessRunning(string $name): bool
{
    exec('pgrep -x '.escapeshellarg($name).' >/dev/null 2>&1', $_, $status);
    return $status === 0;
}

/**
 * Wait up to $timeoutSeconds for a process to exit.
 */
function pmssWaitForProcessExit(string $name, int $timeoutSeconds): bool
{
    $deadline = microtime(true) + max(0, $timeoutSeconds);
    while (microtime(true) < $deadline) {
        if (!pmssProcessRunning($name)) {
            return true;
        }
        usleep(250000); // back off to avoid busy-looping
    }
    return !pmssProcessRunning($name);
}

/**
 * True when systemd knows about the requested unit.
 */
function pmssSystemdUnitExists(string $unit): bool
{
    if (!is_dir('/run/systemd/system')) {
        return false;
    }
    $candidate = $unit;
    if (!preg_match('/\.(service|socket|timer|target|mount|path|slice|scope)$/', $candidate)) {
        $candidate .= '.service';
    }
    exec('systemctl list-unit-files '.escapeshellarg($candidate).' 2>/dev/null', $output, $status);
    if ($status === 0) {
        foreach ($output as $line) {
            if (stripos($line, $candidate) === 0) {
                return true;
            }
        }
    }
    // Fallback: check on-disk unit files and systemctl cat
    if (is_file('/etc/systemd/system/'.$candidate) || is_file('/lib/systemd/system/'.$candidate)) {
        return true;
    }
    exec('systemctl cat '.escapeshellarg($candidate).' >/dev/null 2>&1', $_, $st2);
    return $st2 === 0;
}

/**
 * Run a systemd unit action only when the unit is available on the host.
 */
function pmssSystemdUnitActionIfPresent(string $unit, string $description, string $action): void
{
    if (!is_dir('/run/systemd/system')) {
        logmsg("[SKIP] {$description} (systemd unavailable)");
        return;
    }
    if (!pmssSystemdUnitExists($unit)) {
        logmsg("[SKIP] {$description} (unit {$unit} missing)");
        return;
    }
    $candidate = $unit;
    if ($action === 'enable' && !preg_match('/\.(service|socket|timer|target|mount|path|slice|scope)$/', $candidate)) {
        $candidate .= '.service';
    }
    runStep($description, 'systemctl '.$action.' '.escapeshellarg($candidate));
}

/**
 * Gracefully stop all processes matching the binary name.
 *
 * @param string      $name            Exact binary name (for pgrep -x)
 * @param string      $description     Human-readable description for logging
 * @param string|null $systemdUnit     Optional systemd unit to stop first
 * @param int         $timeoutSeconds  Seconds to wait after SIGTERM before SIGKILL
 */
function killProcess(string $name, string $description, ?string $systemdUnit = null, int $timeoutSeconds = 10): void
{
    if (!pmssProcessRunning($name)) {
        logmsg("[SKIP] {$description} (no {$name} processes)");
        return;
    }

    if ($systemdUnit !== null && pmssSystemdUnitExists($systemdUnit)) {
        runStep($description.' (stop unit)', 'systemctl stop '.escapeshellarg($systemdUnit).' 2>/dev/null');
    } elseif ($systemdUnit !== null && !is_dir('/run/systemd/system')) {
        logmsg("[WARN] {$description} (systemd unavailable for unit {$systemdUnit})");
    }

    runStep($description.' (SIGTERM)', 'pkill -TERM -x '.escapeshellarg($name));

    if (pmssWaitForProcessExit($name, $timeoutSeconds)) {
        logmsg("[OK] {$description} (graceful stop)");
        return;
    }

    runStep($description.' (SIGKILL)', 'pkill -KILL -x '.escapeshellarg($name));
    if (!pmssWaitForProcessExit($name, 5)) {
        logmsg("[WARN] {$description} processes linger after SIGKILL");
    }
}
