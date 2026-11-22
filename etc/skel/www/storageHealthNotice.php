<?php
/**
 * Customer storage notices: compose local observations and presentation.
 * All dependencies remain in the account tree (ADR 0016). Check complete
 * delivery before exposing helpers, so a partial refresh can omit the notice.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/scriptsInc.php';
$pmssStorageFiles = array(
    'storageHealthRaidDisplay.php', 'storageHealthRaid.php', 'storageHealthPressure.php', 'storageHealthDisplay.php',
);
if (function_exists('pmssWelcomeRequireLocalHelpers')) {
    if (!pmssWelcomeRequireLocalHelpers($pmssStorageFiles)) return;
} else {
    // Older delivered loaders only have the checked single-file API; preflight the whole group first.
    if (!function_exists('pmssWelcomeRequireLocalHelper')) return;
    foreach ($pmssStorageFiles as $pmssStorageFile) {
        $pmssStoragePath = __DIR__.'/'.$pmssStorageFile;
        if (!is_file($pmssStoragePath) || is_link($pmssStoragePath) || !is_readable($pmssStoragePath)) return;
    }
    foreach ($pmssStorageFiles as $pmssStorageFile) {
        if (!pmssWelcomeRequireLocalHelper($pmssStorageFile)) return;
    }
}
unset($pmssStorageFiles, $pmssStorageFile, $pmssStoragePath);
if (!function_exists('pmssStorageHealthNoticeHtmlRead')) {
    /** Prefer the specific /home RAID explanation over a generic pressure alert. */
    function pmssStorageHealthNoticeHtmlRead(
        ?string $mountsPath = null,
        ?array $raidEntries = null,
        string $hostPressurePath = '/var/lib/pmss/public/host-pressure.json',
        ?int $now = null,
        string $psiPath = '/proc/pressure/io'
    ): string {
        $raidHtml = pmssStorageHealthHomeRaidNoticeHtmlBuild(
            pmssStorageHealthHomeRaidActivity($mountsPath, $raidEntries)
        );
        if ($raidHtml !== '') return $raidHtml;

        return pmssStorageHealthHostPressureNoticeHtmlBuild(
            pmssStorageHealthHostPressureStateRead($hostPressurePath, $now, $psiPath)
        );
    }
}
