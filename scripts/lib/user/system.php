<?php
/**
 * System-level configuration helpers for user provisioning.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';
require_once __DIR__.'/userConfigStore.php';

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
    $ioArgs = [
        'IOReadBW'    => '--io-read-bw=',
        'IOWriteBW'   => '--io-write-bw=',
        'IOReadIOPS'  => '--io-read-iops=',
        'IOWriteIOPS' => '--io-write-iops=',
    ];
    foreach ($ioArgs as $key => $flag) {
        if (!empty($user[$key])) {
            $args[] = $flag.$user[$key];
        }
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
    static $userConfigStore = null;
    if ($userConfigStore === null) {
        $userConfigStore = new UserConfigStore();
    }
    if (function_exists('pmssUserDockerEnabled') && !pmssUserDockerEnabled($user['name'], $userConfigStore)) {
        if (function_exists('pmssLogStatus')) {
            pmssLogStatus('SKIP', 'Rootless Docker disabled by config for '.$user['name']);
        }
        return;
    }
    runStep('Enabling linger for user', sprintf('loginctl enable-linger %s', escapeshellarg($user['name'])));
    runStep('Installing systemd-container tools', 'apt-get install -y systemd-container');
    runStep(
        'Configuring rootless Docker',
        sprintf('machinectl shell %1$s@ /usr/bin/dockerd-rootless-setuptool.sh install', escapeshellarg($user['name']))
    );
}
