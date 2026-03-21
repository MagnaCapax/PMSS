<?php
/**
 * Process and service helpers for update flows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/commands.php';

/**
 * True when systemd knows about the requested unit.
 */
function pmssSystemdUnitExists(string $unit): bool
{
    if (!is_dir('/run/systemd/system')) {
        return false;
    }
    $candidate = preg_match('/\.(service|socket|timer|target|mount|path|slice|scope)$/', $unit) ? $unit : $unit.'.service';
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
    if (!is_dir('/run/systemd/system')) { logmsg("[SKIP] {$description} (systemd unavailable)"); return; }
    if (!pmssSystemdUnitExists($unit)) { logmsg("[SKIP] {$description} (unit {$unit} missing)"); return; }
    runStep($description, 'systemctl '.$action.' '.escapeshellarg(
        $action === 'enable' && !preg_match('/\.(service|socket|timer|target|mount|path|slice|scope)$/', $unit)
            ? $unit.'.service'
            : $unit
    ));
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
    exec('pgrep -x '.escapeshellarg($name).' >/dev/null 2>&1', $_, $probeStatus);
    if ($probeStatus !== 0) {
        logmsg("[SKIP] {$description} (no {$name} processes)");
        return;
    }
    $probeCommand = 'pgrep -x '.escapeshellarg($name).' >/dev/null 2>&1';

    if ($systemdUnit !== null) {
        if (!is_dir('/run/systemd/system')) {
            logmsg("[WARN] {$description} (systemd unavailable for unit {$systemdUnit})");
        } elseif (pmssSystemdUnitExists($systemdUnit)) {
            runStep($description.' (stop unit)', 'systemctl stop '.escapeshellarg($systemdUnit).' 2>/dev/null');
        }
    }

    runStep($description.' (SIGTERM)', 'pkill -TERM -x '.escapeshellarg($name));

    $deadline = microtime(true) + max(0, $timeoutSeconds);
    while (true) {
        exec($probeCommand, $_, $probeStatus);
        if ($probeStatus !== 0) {
            logmsg("[OK] {$description} (graceful stop)");
            return;
        }
        if (microtime(true) >= $deadline) {
            break;
        }
        usleep(250000); // back off to avoid busy-looping
    }

    runStep($description.' (SIGKILL)', 'pkill -KILL -x '.escapeshellarg($name));

    $deadline = microtime(true) + 5;
    while (true) {
        exec($probeCommand, $_, $probeStatus);
        if ($probeStatus !== 0) {
            return;
        }
        if (microtime(true) >= $deadline) {
            break;
        }
        usleep(250000); // back off to avoid busy-looping
    }

    logmsg("[WARN] {$description} processes linger after SIGKILL");
}
