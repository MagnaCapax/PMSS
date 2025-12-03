<?php
/**
 * System-level configuration helpers for user provisioning.
 */

require_once __DIR__.'/helpers.php';

function userEnsureShell(array $user): void
{
    if (!file_exists('/bin/bash')) {
        return;
    }
    userRunCommand('Ensuring bash shell', sprintf('chsh -s /bin/bash %s', escapeshellarg($user['name'])));
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
    if (array_key_exists('cpuQuotaPercent', $user) && $user['cpuQuotaPercent'] !== '' && $user['cpuQuotaPercent'] !== null) {
        $quotaVal = $user['cpuQuotaPercent'];
        $quotaLabel = (is_string($quotaVal) && strtolower((string)$quotaVal) === 'infinity')
            ? 'infinity'
            : $quotaVal.'%';
        echo 'Applying CPU quota: '.$quotaLabel."\n";
        $args[] = '--cpu-quota-percent=' . $quotaVal;
    }

    userRunCommand(
        'Configuring cgroups',
        pmssBuildCommand('php', $args)
    );
}

function userEnableLingerAndDocker(array $user): void
{
    userRunCommand('Enabling linger for user', sprintf('loginctl enable-linger %s', escapeshellarg($user['name'])));
    userRunCommand('Installing systemd-container tools', 'apt-get install -y systemd-container');
    userRunCommand(
        'Configuring rootless Docker',
        sprintf('machinectl shell %1$s@ /usr/bin/dockerd-rootless-setuptool.sh install', escapeshellarg($user['name']))
    );
}
