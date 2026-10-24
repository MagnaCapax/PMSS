<?php
/**
 * Customer storage notices: compose local observations and presentation.
 * All dependencies remain in the account tree (ADR 0016). Check complete
 * delivery before exposing helpers, so a partial refresh can omit the notice.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/scriptsInc.php';
$pmssStorageModules = ['storageHealthRaidDisplay', 'storageHealthRaid', 'storageHealthPressure', 'storageHealthDisplay'];
foreach ($pmssStorageModules as $pmssStorageModule) {
    $pmssStoragePath = __DIR__.'/'.$pmssStorageModule.'.php';
    if (!is_file($pmssStoragePath) || is_link($pmssStoragePath) || !is_readable($pmssStoragePath)) return;
}
foreach ($pmssStorageModules as $pmssStorageModule) require_once __DIR__.'/'.$pmssStorageModule.'.php';
unset($pmssStorageModules, $pmssStorageModule, $pmssStoragePath);
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
