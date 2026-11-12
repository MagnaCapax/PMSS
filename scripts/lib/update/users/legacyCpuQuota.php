<?php
/** Legacy CPU-quota repair; loaded by the userMaintenance.php facade. */

function pmssUserMaintenanceLegacyCpuQuotaNeedsFix(string $sliceDir): bool
{
    foreach (is_dir($sliceDir) ? (glob($sliceDir.'/*.conf') ?: []) : [] as $file) {
        $content = @file_get_contents($file);
        if ($content !== false && preg_match('/^CPUQuota\s*=\s*85%$/m', $content)) {
            return true;
        }
    }

    return false;
}

function pmssUserMaintenanceRepairLegacyCpuQuota(string $user): void
{
    $uinfo = pmssUserAccountLookup($user);
    if ($uinfo === null) {
        return;
    }

    $uid = (int) $uinfo['uid'];
    if (!pmssUserMaintenanceLegacyCpuQuotaNeedsFix('/etc/systemd/system/user-'.$uid.'.slice.d')) {
        return;
    }

    $threads = pmssTotalCpuThreads();
    $newQuota = ($threads > 0) ? ($threads * 85) : 600;
    runStep(
        "Fixing legacy 85% CPUQuota for {$user}",
        'systemctl set-property '.escapeshellarg('user-'.$uid.'.slice').' CPUQuota='.$newQuota.'%'
    );
}
