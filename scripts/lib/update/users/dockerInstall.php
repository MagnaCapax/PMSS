<?php
/** Customer-usable Docker unit repair and installation; loaded by docker.php. @license GPL-3.0-only */

/** Read the first ExecStart binary from a rootless Docker unit. */
function pmssUserDockerUnitExecBinary(string $unit): ?string
{
    $lines = @file($unit, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return null;
    }

    foreach ($lines as $line) {
        $trim = pmssConfigLineTrimmed($line, ['#', ';']);
        if ($trim === '' || strpos($trim, 'ExecStart=') !== 0) {
            continue;
        }

        $command = trim(substr($trim, strlen('ExecStart=')));
        if ($command !== '' && $command[0] === '-') {
            $command = ltrim(substr($command, 1));
        }
        $parts = pmssConfigLineColumns($command, 1, []);
        return $parts && $parts[0] !== '' ? trim($parts[0], "\"'") : null;
    }

    return null;
}

/** Run dockerd-rootless-setuptool.sh when the user's docker.service is missing or stale. */
function pmssEnsureRootlessDockerInstalled(string $user): void
{
    if (!pmssUserMaintenanceUsernameAllowed($user, 'pmssEnsureRootlessDockerInstalled')) {
        return;
    }

    $uinfo = pmssUserAccountLookup($user);
    if ($uinfo === null || !isset($uinfo['dir'])) {
        pmssUserLog($user, '[WARN] Unable to resolve passwd entry; skipping rootless Docker install');
        return;
    }

    $home = $uinfo['dir'];
    $unit = $home.'/.config/systemd/user/docker.service';

    // The docker.service unit is a REAL, customer-usable unit: customers start rootless
    // Docker with `systemctl --user start docker.service` (the Rootless Docker KB article;
    // GH#794, closed/complete-verify, treats a broken `systemctl --user` as a FAULT TO FIX,
    // not an unsupported path). PMSS's watchdog uses `nohup dockerd-rootless.sh` (ADR-0027)
    // for its OWN liveness path, but the unit stays the customer path and MUST remain
    // functional. Do NOT mask it (GH#873 correction — masking the unit broke the documented
    // customer start path). The `systemctl --user restart` collision with the watchdog daemon
    // is a documented UX nuance (docs steer to pkill recovery), not grounds to disable the unit.

    // Restore any unit a prior release masked (symlink -> /dev/null) so the customer-usable
    // unit comes back; the guarded reinstall below recreates the real unit.
    if (is_link($unit) && @readlink($unit) === '/dev/null') {
        $realHome = realpath($home);
        $unitDir = realpath(dirname($unit));
        if ($realHome !== false && $unitDir !== false && strpos($unitDir.'/', $realHome.'/') === 0) {
            @unlink($unit);
            clearstatcache();
        }
    }

    if (is_file($unit)) {
        $execBinary = pmssUserDockerUnitExecBinary($unit);
        if ($execBinary !== null && is_file($execBinary)) {
            pmssUserLog($user, '[SKIP] Rootless Docker unit present and valid; install-marker present');
            return;
        }

        pmssUserLog($user, '[WARN] Rootless Docker unit appears stale ('.($execBinary === null ? 'missing ExecStart' : 'missing ExecStart binary '.$execBinary).'); removing to allow reinstall');
        $realHome = realpath($home);
        $realUnit = realpath($unit);
        if ($realHome === false || $realUnit === false || strpos($realUnit, $realHome.'/') !== 0) {
            pmssUserLog($user, '[WARN] Refusing to remove docker.service outside expected home path');
            return;
        }
        if (!@unlink($unit)) {
            pmssUserLog($user, '[WARN] Failed to remove stale docker.service; skipping reinstall');
            return;
        }
    }

    // Manager-independent rootless-Docker install (ADR-0027): run the local setuptool AS the
    // user (pmssRunAndLog $asUser=true wraps via su) with XDG_RUNTIME_DIR set, NOT via the
    // per-user-manager container-shell path which needs a working per-user systemd manager
    // (fails under cgroup v2 + hidepid=2, systemd issue 12955). Converged onto the local
    // setuptool instead of the upstream network installer (reproducible + sovereign; matches
    // userConfigRuntime). Daemon launched separately via nohup dockerd-rootless.sh.
    $installCmd = 'export XDG_RUNTIME_DIR=/run/user/$(id -u); export PATH=$PATH:/usr/sbin:/sbin; /usr/bin/dockerd-rootless-setuptool.sh install';
    pmssRunAndLog($user, 'rootless Docker setuptool install', $installCmd, true);
    clearstatcache();
    pmssUserLog($user, is_file($unit)
        ? '[OK] Rootless Docker installed for user'
        : '[WARN] Rootless Docker install script completed but docker.service is still missing');
}
