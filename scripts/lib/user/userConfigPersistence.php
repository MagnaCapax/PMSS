<?php
/** Durable user-config reads and guarded JSON publication; loaded by userConfigStore.php.
 * @license GPL-3.0-only
 */

/** Read canonical files before projecting the same validated user map as the legacy store. */
function pmssUserConfigCanonicalUsersRead(string $userDir): array
{
    if (!is_dir($userDir) || empty($files = glob($userDir.'/*.json'))) {
        return [];
    }

    $users = [];
    foreach ($files as $file) {
        $users[basename($file, '.json')] = pmssJsonFileReadAssoc($file);
    }
    return pmssUserConfigMapNormalise($users);
}

/** Accept both historical aggregate shapes; the canonical caller retains precedence. */
function pmssUserConfigLegacyUsersRead(string $path): array
{
    $data = pmssJsonFileReadAssoc($path);
    if (!is_array($data)) {
        return [];
    }

    return pmssUserConfigMapNormalise(is_array($data['users'] ?? null) ? $data['users'] : $data);
}

/** Preserve account-home preparation and managed-writer ownership policy for both destinations. */
function pmssUserConfigJsonWrite(string $path, array $payload, int $mode, string $owner, string $group): bool
{
    $dir = dirname($path);
    if (pmssManagedAccountHomePath($dir)) {
        $context = pmssRootStageContext($dir.'/.pmss-check');
        $identity = pmssAccountHomeIdentity($dir.'/.pmss-check');
        if ($identity === null || ($context === null && @posix_geteuid() !== $identity[2])
            || (!is_dir($dir) && !pmssAccountDirectoryEnsure($identity[1], $identity[0], $dir, 0750, 0755))) return false;
    } elseif (!pmssDirEnsureExists($dir, 0750)) {
        return false;
    }
    $encoded = pmssJsonEncodePretty($payload);
    if ($encoded === null) return false;

    return pmssWriteManagedFile($path, $encoded, $owner, $group, $mode);
}
