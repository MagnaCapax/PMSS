<?php
/** Provisioning-tree permission policy, localnet access and private config records.
 * @license GPL-3.0-only
 */
require_once __DIR__.'/systemPermissionsTargets.php';
require_once __DIR__.'/configPermissions.php';

/** Apply broad tree modes before the later service-secret restrictions. */
function pmssSystemPermissionsTreesApply(array &$exitCodes): void
{
    // Keep the content, directory, ownership and file passes in their original order.
    $permissionTargets = [
        '/etc/skel' => [
            'content'   => ['Hardening /etc/skel content permissions', 'find /etc/skel -mindepth 1 -not -type l -perm /002 -exec chmod o-w -- {} +'],
            'directory' => ['Restricting /etc/skel directory permissions', 'chmod 770 /etc/skel'],
            'files'     => ['Removing other permissions from /etc/skel files', "find /etc/skel -type f -perm /007 -exec chmod o-rwx -- {} +"],
        ],
        '/etc/seedbox' => [
            'content'   => ['Hardening /etc/seedbox content permissions', 'find /etc/seedbox -mindepth 1 -not -type l -perm /002 -exec chmod o-w -- {} +'],
            'directory' => ['Ensuring /etc/seedbox is traversable', 'chmod o+x /etc/seedbox'],
            'files'     => ['Removing other permissions from /etc/seedbox files', "find /etc/seedbox -type f -perm /007 -exec chmod o-rwx -- {} +"],
        ],
    ];
    foreach ($permissionTargets as $path => $target) {
        if (!pmssPermissionTargetExists($path)) {
            if (!is_link($path)) logmsg(sprintf('Skipping %s permission adjustments; directory missing', $path));
            continue;
        }
        $exitCodes[] = runStep($target['content'][0], $target['content'][1]);
        $exitCodes[] = runStep($target['directory'][0], $target['directory'][1]);
        // Do not use 775: some locations deliberately retain different permissions.
    }
    foreach ($permissionTargets as $path => $target) pmssEnsureRootOwnership($path, $exitCodes);

    // Root-side catalog readers refuse a group-writable catalog or parent directory.
    pmssPermissionStepsRun('/etc/skel/www', [
        ['Removing group write from the toggle-app catalog path', "find /etc/skel/www -maxdepth 1 \\( -path /etc/skel/www -o -name toggleAppCatalog.php \\) -not -type l -perm /022 -exec chmod go-w -- {} +"],
    ], $exitCodes);
    foreach ($permissionTargets as $path => $target) pmssPermissionStepsRun($path, [$target['files']], $exitCodes);

    // These best-effort chmod results have never contributed to the CLI exit status.
    foreach ([
        ['/etc/openvpn', 0771, true], ['/etc/openvpn/openvpn.conf', 0640, false],
        ['/etc/openvpn/easy-rsa', 0771, true], ['/etc/seedbox/config/localnet', 0664, false],
        ['/etc/seedbox/localnet', 0664, false],
    ] as [$path, $mode, $directory]) {
        if (pmssPermissionTargetExists($path, $directory)) @chmod($path, $mode);
    }

    $configDir = '/etc/seedbox/config';
    if (pmssPermissionTargetExists($configDir)) {
        // Additive /etc staging leaves deleted api.* artifacts on older hosts. Retire
        // these exact regular files before the general 0664 sweep can re-expose them.
        foreach (['api.server', 'api.debug', 'api.localKey', 'api.remoteKey'] as $deadFile) {
            $deadPath = $configDir.'/'.$deadFile;
            if (is_file($deadPath) && !is_link($deadPath)) @unlink($deadPath);
        }
        pmssConfigPermissionsSweep($configDir);
    }
}
