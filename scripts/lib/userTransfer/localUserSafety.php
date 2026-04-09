<?php
/**
 * Local user and home safety checks for user transfers.
 *
 * @license GPL-3.0-only
 */

/**
 * Ensure the local home exists, is not a symlink, and matches passwd metadata.
 */
function pmssUserTransferAssertSafeLocalHome(string $user): string
{
    $homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
    $expected = $homeRoot.'/'.$user;

    $real = realpath($expected);
    if ($real === false || $real !== $expected || !is_dir($expected) || is_link($expected)) {
        throw new RuntimeException('Local user home does not look safe: '.$expected, 1);
    }

    $pw = null;
    if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam($user);
        $pw = is_array($pw)
            ? [
                'uid' => (int) ($pw['uid'] ?? -1),
                'gid' => (int) ($pw['gid'] ?? -1),
                'dir' => (string) ($pw['dir'] ?? ''),
            ]
            : null;
    } elseif (($parts = pmssColonRecordFieldsLookup('/etc/passwd', $user, 7)) !== null) {
        $pw = [
            'uid' => (int) $parts[2],
            'gid' => (int) $parts[3],
            'dir' => (string) $parts[5],
        ];
    }

    if ($pw === null) {
        throw new RuntimeException('Local user not present in /etc/passwd: '.$user, 1);
    }
    if (($pw['uid'] ?? -1) < 1000) {
        throw new RuntimeException('Refusing to transfer system user: '.$user, 1);
    }
    $pwHome = rtrim((string) ($pw['dir'] ?? ''), '/');
    if ($pwHome !== $expected) {
        throw new RuntimeException('Local user home mismatch for '.$user.' (expected '.$expected.'; passwd has '.$pwHome.')', 1);
    }

    return $expected;
}

/**
 * Return true when the resolved path lives under the resolved home directory.
 */
function pmssUserTransferIsPathWithinHome(string $path, string $home): bool
{
    $realHome = realpath($home);
    $realPath = realpath($path);
    if ($realHome === false || $realPath === false) {
        return false;
    }
    $prefix = rtrim($realHome, '/').'/';
    return strpos(rtrim($realPath, '/').'/', $prefix) === 0;
}
