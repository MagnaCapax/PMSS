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
    $slicePath = sprintf('/etc/systemd/system/user-%d.slice.d', $user['id']);
    if (!file_exists($slicePath)) {
        mkdir($slicePath, 0755, true);
    }
    $template = file_get_contents('/etc/seedbox/config/template.user-slice.conf');
    $rendered = str_replace(
        ['##USER_MEMORY##', '##USER_MEMORY_MAX##', '##USER_CPUWEIGHT##', '##USER_IOWEIGHT##'],
        [$user['memory'], $user['memory'] * 2, $user['CPUWeight'], $user['IOWeight']],
        $template
    );

    if (file_exists($slicePath.'/99-pmss.conf')) {
        unlink($slicePath.'/99-pmss.conf');
    }

    file_put_contents($slicePath.'/90-pmss-user.conf', $rendered);
    chmod($slicePath.'/90-pmss-user.conf', 0644);
    userRunCommand('Reloading systemd configuration', 'systemctl daemon-reload');
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
