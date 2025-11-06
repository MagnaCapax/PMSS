<?php
/**
 * Process and service helpers for update flows.
 */

require_once __DIR__.'/commands.php';
require_once __DIR__.'/../logging.php';

if (!function_exists('pmssProcessRunning')) {
    /**
     * True when any process with the exact binary name is running.
     */
    function pmssProcessRunning(string $name): bool
    {
        exec('pgrep -x '.escapeshellarg($name).' >/dev/null 2>&1', $_, $status);
        return $status === 0;
    }
}

if (!function_exists('pmssWaitForProcessExit')) {
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
}

if (!function_exists('pmssSystemdAvailable')) {
    /**
     * Detect whether systemd is managing this host.
     */
    function pmssSystemdAvailable(): bool
    {
        return is_dir('/run/systemd/system');
    }
}

if (!function_exists('pmssSystemdUnitExists')) {
    /**
     * True when systemd knows about the requested unit.
     */
    function pmssSystemdUnitExists(string $unit): bool
    {
        if (!pmssSystemdAvailable()) {
            return false;
        }
        exec('systemctl list-unit-files '.escapeshellarg($unit).' 2>/dev/null', $output, $status);
        if ($status !== 0) {
            return false;
        }
        foreach ($output as $line) {
            if (stripos($line, $unit) === 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('killProcess')) {
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
        } elseif ($systemdUnit !== null && !pmssSystemdAvailable()) {
            logmsg("[WARN] {$description} (systemd unavailable for unit {$systemdUnit})");
        }

        $binaryArg = escapeshellarg($name);
        runStep($description.' (SIGTERM)', 'pkill -TERM -x '.$binaryArg);

        if (pmssWaitForProcessExit($name, $timeoutSeconds)) {
            logmsg("[OK] {$description} (graceful stop)");
            return;
        }

        runStep($description.' (SIGKILL)', 'pkill -KILL -x '.$binaryArg);
        if (!pmssWaitForProcessExit($name, 5)) {
            logmsg("[WARN] {$description} processes linger after SIGKILL");
        }
    }
}

if (!function_exists('disableUnitIfPresent')) {
    /**
     * Disable a systemd unit only when it exists on the target host.
     */
    function disableUnitIfPresent(string $unit, string $description): void
    {
        if (!pmssSystemdAvailable()) {
            logmsg("[SKIP] {$description} (systemd unavailable)");
            return;
        }
        if (!pmssSystemdUnitExists($unit)) {
            logmsg("[SKIP] {$description} (unit {$unit} missing)");
            return;
        }
        runStep($description, 'systemctl disable '.escapeshellarg($unit));
    }
}
