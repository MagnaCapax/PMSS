<?php
/**
 * Compile iprange after the package phase completes.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/packages/helpers.php';

if (empty($GLOBALS['PMSS_PACKAGES_READY'])) {
    logmsg('[WARN] Skipping iprange build: package phase not complete');
    return;
}

if (file_exists('/usr/local/bin/iprange')) {
    return;
}

$dependencies = ['build-essential', 'gcc', 'make', 'gawk'];
$missing = array_values(array_filter($dependencies, static function (string $pkg): bool {
    return pmssPackageStatus($pkg) !== 'install ok installed';
}));

if (!empty($missing)) {
    $message = 'Skipping iprange build: missing toolchain packages '.implode(', ', $missing);
    logmsg('[WARN] '.$message);
    return;
}

$compileCmd = implode(' && ', [
    'set -e',
    'mkdir -p /root/compile',
    'cd /root/compile',
    'rm -rf iprange-1.0.4 iprange-1.0.4.tar.gz',
    'wget http://pulsedmedia.com/remote/pkg/iprange-1.0.4.tar.gz -O iprange-1.0.4.tar.gz',
    'tar -xzf iprange-1.0.4.tar.gz',
    'cd iprange-1.0.4',
    './configure',
    'make -j6',
    'make install'
]);

runStep('Building iprange from source', $compileCmd);
