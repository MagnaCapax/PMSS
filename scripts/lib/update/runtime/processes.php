<?php
/**
 * Process and service helpers for update flows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/commands.php';

function pmssSystemdActionSkipReason(?string $unit = null, bool $skipInDryRun = false, bool $skipInStrictTestMode = false): string
{
    if (($skipInDryRun && pmssEnvFlagEnabled('PMSS_DRY_RUN')) || ($skipInStrictTestMode && pmssTestModeEnabled())) return 'test/dry-run';
    if (!pmssEnvFlagEnabled('PMSS_DRY_RUN') && !pmssSystemdRuntimeAvailable()) return 'systemd unavailable';
    if ($unit !== null && !pmssSystemdUnitNameIsSafe($unit)) return 'invalid unit name';
    if ($unit !== null && !pmssEnvFlagEnabled('PMSS_DRY_RUN') && !pmssSystemdUnitExists($unit)) return 'unit '.$unit.' missing';
    return '';
}

/**
 * True when systemd knows about the requested unit.
 */
function pmssSystemdUnitExists(string $unit): bool
{
    if (!pmssSystemdRuntimeAvailable()) {
        return false;
    }
    $unit = trim($unit);
    if (!pmssSystemdUnitNameIsSafe($unit)) {
        return false;
    }
    $candidate = pmssSystemdUnitDefaultServiceName($unit);
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
    $action = trim($action);
    if (!pmssSystemdUnitActionNameIsSafe($action)) { logmsg("[SKIP] {$description} (invalid systemd action)"); return; }
    if (($skipReason = pmssSystemdActionSkipReason($unit)) !== '') { logmsg("[SKIP] {$description} ({$skipReason})"); return; }
    $target = $action === 'enable' ? pmssSystemdUnitDefaultServiceName($unit) : $unit;
    runStep($description, 'systemctl '.$action.' '.escapeshellarg($target));
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
    $name = trim($name);
    if (!pmssCommandBinaryNameIsSafe($name)) {
        logmsg("[WARN] {$description} (invalid process name)");
        return;
    }

    $probeCommand = 'pgrep -x '.escapeshellarg($name).' >/dev/null 2>&1';
    exec($probeCommand, $_, $probeStatus);
    if ($probeStatus !== 0) {
        logmsg("[SKIP] {$description} (no {$name} processes)");
        return;
    }

    if ($systemdUnit !== null) {
        if (!pmssSystemdUnitNameIsSafe($systemdUnit)) {
            logmsg("[WARN] {$description} (invalid systemd unit {$systemdUnit})");
        } elseif (!pmssSystemdRuntimeAvailable()) {
            logmsg("[WARN] {$description} (systemd unavailable for unit {$systemdUnit})");
        } elseif (pmssSystemdUnitExists($systemdUnit)) {
            runStep($description.' (stop unit)', 'systemctl stop '.escapeshellarg($systemdUnit).' 2>/dev/null');
        }
    }

    foreach (['TERM' => max(0, $timeoutSeconds), 'KILL' => 5] as $signal => $waitSeconds) {
        runStep($description.' (SIG'.$signal.')', 'pkill -'.$signal.' -x '.escapeshellarg($name));

        $deadline = microtime(true) + $waitSeconds;
        while (true) {
            exec($probeCommand, $_, $probeStatus);
            if ($probeStatus !== 0) {
                if ($signal === 'TERM') {
                    logmsg("[OK] {$description} (graceful stop)");
                }
                return;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(250000); // back off to avoid busy-looping
        }
    }

    logmsg("[WARN] {$description} processes linger after SIGKILL");
}
