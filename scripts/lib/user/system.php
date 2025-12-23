<?php
/**
 * System-level configuration helpers for user provisioning.
 */

require_once __DIR__.'/../update/runtime/commands.php';

function userEnsureShell(array $user): void
{
    if (!file_exists('/bin/bash')) {
        return;
    }
    runStep('Ensuring bash shell', sprintf('chsh -s /bin/bash %s', escapeshellarg($user['name'])));
}

function userConfigureSystemdSlice(array $user): void
{
    // Delegate cgroup configuration to the dedicated utility.
    // This ensures v1/v2 compatibility and automatic weight calculation.
    $args = [
        '/scripts/util/userConfigCgroup.php',
        $user['name'],
        '--apply',
        '--memory-high=' . $user['memory'],
    ];

    if (!empty($user['CPUWeight']) && $user['CPUWeight'] > 0) {
        $args[] = '--cpu-weight=' . $user['CPUWeight'];
    }
    if (!empty($user['IOWeight']) && $user['IOWeight'] > 0) {
        $args[] = '--io-weight=' . $user['IOWeight'];
    }

    // Optional I/O throttles
    if (!empty($user['IOReadBW'])) {
        $args[] = '--io-read-bw=' . $user['IOReadBW'];
    }
    if (!empty($user['IOWriteBW'])) {
        $args[] = '--io-write-bw=' . $user['IOWriteBW'];
    }
    if (!empty($user['IOReadIOPS'])) {
        $args[] = '--io-read-iops=' . $user['IOReadIOPS'];
    }
    if (!empty($user['IOWriteIOPS'])) {
        $args[] = '--io-write-iops=' . $user['IOWriteIOPS'];
    }
    if (isset($user['cpuQuotaPercent']) && $user['cpuQuotaPercent'] !== '') {
        $quotaVal = $user['cpuQuotaPercent'];
        $quotaLabel = (is_string($quotaVal) && strtolower((string)$quotaVal) === 'infinity')
            ? 'infinity'
            : $quotaVal.'%';
        echo 'Applying CPU quota: '.$quotaLabel."\n";
        $args[] = '--cpu-quota-percent=' . $quotaVal;
    }

    runStep(
        'Configuring cgroups',
        pmssBuildCommand('php', $args)
    );
}

function userEnableLingerAndDocker(array $user): void
{
    runStep('Enabling linger for user', sprintf('loginctl enable-linger %s', escapeshellarg($user['name'])));
    runStep('Installing systemd-container tools', 'apt-get install -y systemd-container');
    runStep(
        'Configuring rootless Docker',
        sprintf('machinectl shell %1$s@ /usr/bin/dockerd-rootless-setuptool.sh install', escapeshellarg($user['name']))
    );
}
