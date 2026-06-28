<?php
/**
 * Dist-upgrade boot readiness and service repair helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssEnsureInitrdAfterDistUpgrade(): void
{
    $kernelImages = glob('/boot/vmlinuz-*');
    if (empty($kernelImages)) {
        logMessage('[WARN] dist-upgrade: no kernel images found under /boot; skipping initrd check');
        return;
    }

    $versions = [];
    foreach ($kernelImages as $path) {
        $base = basename($path);
        if (strpos($base, 'vmlinuz-') === 0 && ($version = substr($base, strlen('vmlinuz-'))) !== '' && $version !== 'old') {
            $versions[] = $version;
        }
    }
    if (empty($versions)) {
        logMessage('[WARN] dist-upgrade: no usable kernel versions detected; skipping initrd check');
        return;
    }

    usort($versions, 'version_compare');
    $latest = end($versions);
    if ($latest === false || $latest === '') {
        logMessage('[WARN] dist-upgrade: unable to resolve newest kernel version; skipping initrd check');
        return;
    }

    $initrd = '/boot/initrd.img-'.$latest;
    if (file_exists($initrd)) {
        logMessage('[SKIP] dist-upgrade: initrd present for kernel '.$latest);
        return;
    }

    logMessage('dist-upgrade: initrd missing for kernel '.$latest.'; generating with update-initramfs');
    $rc = pmssDistUpgradeRunLockedCommand('update-initramfs -c -k '.escapeshellarg($latest), '[WARN] dist-upgrade: dpkg lock did not clear; skipping initrd generation');
    if ($rc === null) {
        return;
    }
    if ($rc !== 0) {
        logMessage('[WARN] dist-upgrade: update-initramfs failed for kernel '.$latest);
        return;
    }
    logMessage(file_exists($initrd) ? 'dist-upgrade: initrd generated for kernel '.$latest : '[WARN] dist-upgrade: initrd still missing for kernel '.$latest.' after update-initramfs');
}

function pmssVerifyDistUpgradeBootReadiness(
    ?string $mdstatPath = null,
    ?string $grubConfigPath = null,
    ?string $mdadmConfigPath = null,
    ?string $initramfsMdadmPath = null
): void {
    $mdstatPath = $mdstatPath ?? '/proc/mdstat';
    $grubConfigPath = $grubConfigPath ?? '/boot/grub/grub.cfg';
    $mdadmConfigPath = $mdadmConfigPath ?? '/etc/mdadm/mdadm.conf';
    $initramfsMdadmPath = $initramfsMdadmPath ?? '/etc/initramfs-tools/conf.d/mdadm';

    pmssDistUpgradeVerifyReadablePattern($mdstatPath, '[WARN] dist-upgrade: unable to read '.$mdstatPath.'; RAID health check skipped', '/\[[U_]*_[U_]*\]/', true, '[WARN] dist-upgrade: degraded RAID array detected in '.$mdstatPath.'; inspect before reboot', '[SKIP] dist-upgrade: RAID arrays appear healthy');
    pmssDistUpgradeVerifyGrubConfig($grubConfigPath);

    foreach ([
        [$mdadmConfigPath, '[WARN] dist-upgrade: unable to read '.$mdadmConfigPath.'; mdadm config check skipped', '/^\s*ARRAY\s+\S+/m', false, '[WARN] dist-upgrade: '.$mdadmConfigPath.' lacks ARRAY definitions; regenerate before reboot', '[SKIP] dist-upgrade: mdadm ARRAY definitions found'],
        [$initramfsMdadmPath, '[WARN] dist-upgrade: unable to read '.$initramfsMdadmPath.'; BOOT_DEGRADED verification skipped', '/^\s*BOOT_DEGRADED\s*=\s*true\s*$/mi', false, '[WARN] dist-upgrade: '.$initramfsMdadmPath.' missing BOOT_DEGRADED=true; degraded RAID boot may fail', '[SKIP] dist-upgrade: BOOT_DEGRADED=true is configured'],
    ] as $check) {
        pmssDistUpgradeVerifyReadablePattern($check[0], $check[1], $check[2], $check[3], $check[4], $check[5]);
    }
}

function pmssDistUpgradeVerifyGrubConfig(string $grubConfigPath): void
{
    if (!is_file($grubConfigPath)) {
        logMessage('[WARN] dist-upgrade: missing '.$grubConfigPath.'; run update-grub before reboot');
        return;
    }

    $grubSize = @filesize($grubConfigPath);
    logMessage($grubSize === false || $grubSize < 1000 ? '[WARN] dist-upgrade: grub config looks too small ('.(int) $grubSize.' bytes); verify '.$grubConfigPath : '[SKIP] dist-upgrade: grub config present at '.$grubConfigPath);
}

function pmssDistUpgradeVerifyReadablePattern(string $path, string $unreadableMessage, string $pattern, bool $warnWhenMatched, string $warnMessage, string $skipMessage): void
{
    if (($contents = pmssReadRegularFileContents($path)) === null) {
        logMessage($unreadableMessage);
        return;
    }

    $matched = preg_match($pattern, $contents) === 1;
    logMessage($matched === $warnWhenMatched ? $warnMessage : $skipMessage);
}

function pmssMdstatHasDegradedArrays(string $mdstat): bool
{
    return $mdstat !== '' && preg_match('/\[[U_]*_[U_]*\]/', $mdstat) === 1;
}

function pmssRepairNginxAfterDistUpgrade(): void
{
    if (pmssCommandPath('nginx') === '') {
        logMessage('[SKIP] dist-upgrade: nginx not installed; skipping ABI check');
        return;
    }

    if (runCommand('nginx -t', true) === 0) {
        logMessage('[SKIP] dist-upgrade: nginx config test passed');
        return;
    }

    $last = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] ?? ['stderr' => '', 'stdout' => ''];
    $combined = (string) ($last['stderr'] ?? '')."\n".(string) ($last['stdout'] ?? '');
    if (stripos($combined, 'module is not binary compatible') === false) {
        logMessage('[WARN] dist-upgrade: nginx -t failed, but no ABI mismatch detected; skipping reinstall');
        return;
    }

    logMessage('dist-upgrade: nginx ABI mismatch detected; purging and reinstalling nginx packages');
    list($env, $hasTty) = pmssDistUpgradeAptEnv();
    $lockMessage = '[WARN] dist-upgrade: dpkg lock did not clear; skipping nginx reinstall';
    if (pmssDistUpgradeRunLockedCommand("$env apt-get purge -y 'nginx*'", $lockMessage, $hasTty) === null) {
        return;
    }
    $installRc = pmssDistUpgradeRunLockedCommand(pmssDistUpgradeAptCommand($env, 'install', 'nginx nginx-full nginx-common'), $lockMessage, $hasTty);
    if ($installRc === null) {
        return;
    }
    if ($installRc !== 0) {
        logMessage('[WARN] dist-upgrade: nginx reinstall failed; leaving existing config in place');
        return;
    }

    runCommand('/scripts/util/createNginxConfig.php --restart', true, null, $hasTty);
    if (runCommand('nginx -t', true, null, $hasTty) !== 0) {
        logMessage('[WARN] dist-upgrade: nginx -t still failing after reinstall');
    }
}
