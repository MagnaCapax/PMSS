<?php
/** Managed WebUI line transformation, separate from running-daemon reconciliation. */
require_once __DIR__.'/qbittorrentConfig.php';
/** Update every copy of both managed keys in every Preferences section. */
function pmssQbittorrentWebUiAuthForce(string $config, ?string $build = null): string
{
    list($lines, $trailing, $lineEnding) = pmssQbittorrentConfigSplitLines($config);
    $passwordPresent = pmssQbittorrentWebUiValue($config, 'Password_PBKDF2', $build) !== null;
    $managed = [
        'WebUI\\LocalHostAuth' => 'true',
        'WebUI\\AuthSubnetWhitelistEnabled' => 'false',
    ];
    // The sentinel is stable across passes and is never added over an existing value.
    if (!$passwordPresent) $managed['WebUI\\Password_PBKDF2'] = pmssQbittorrentWebUiAuthSentinel();
    $result = [];
    $inPreferences = false;
    $hasPreferences = false;
    $seen = [];
    foreach ($lines as $line) {
        // Finish each section independently, including repeated Preferences sections.
        if (preg_match('/^\[[^\]]+\]$/', $line) === 1) {
            if ($inPreferences) foreach ($managed as $key => $value) if (!isset($seen[$key])) $result[] = $key.'='.$value;
            $inPreferences = $line === '[Preferences]';
            $hasPreferences = $hasPreferences || $inPreferences;
            $seen = [];
        }
        if ($inPreferences) {
            // Replace every copy so a later duplicate cannot restore an old value.
            foreach ($managed as $key => $value) {
                if (strpos($line, $key.'=') !== 0) continue;
                $line = $key.'='.$value;
                $seen[$key] = true;
                break;
            }
        }
        $result[] = $line;
    }
    if ($inPreferences) foreach ($managed as $key => $value) if (!isset($seen[$key])) $result[] = $key.'='.$value;
    if (!$hasPreferences) {
        // The template may be incomplete on older accounts.
        if ($result !== [] && end($result) !== '') $result[] = '';
        $result[] = '[Preferences]';
        foreach ($managed as $key => $value) $result[] = $key.'='.$value;
    }
    $updated = pmssQbittorrentConfigJoinLines($result, $trailing, $lineEnding);
    if (pmssQbittorrentWebUiSection($config)[0] === 'WebUI') {
        $password = pmssQbittorrentWebUiValue($config, 'Password_PBKDF2', $build) ?? pmssQbittorrentWebUiAuthSentinel();
        $managed['WebUI\\Password_PBKDF2'] = $password;
        foreach ($managed as $key => $value) $updated = pmssQbittorrentConfigUpsert($updated, 'Preferences', $key, $value);
        // Replace duplicates on both sides so an older build also gets the authoritative hash.
        $webLines = [];
        $webSection = '';
        foreach (pmssQbittorrentConfigSplitLines($updated)[0] as $line) {
            if (preg_match('/^\[[^\]]+\]$/', $line) === 1) $webSection = $line;
            if ($webSection === '[WebUI]' || $webSection === '[Preferences]') foreach ($managed as $key => $value) {
                $shortKey = $webSection === '[WebUI]' ? substr($key, 6) : $key;
                if (strpos($line, $shortKey.'=') === 0) $line = $shortKey.'='.$value;
            }
            $webLines[] = $line;
        }
        $updated = pmssQbittorrentConfigJoinLines($webLines, $trailing, $lineEnding);
    }
    return $updated;
}
