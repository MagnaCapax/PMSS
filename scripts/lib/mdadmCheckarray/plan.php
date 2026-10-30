<?php
/** Array selection and source precedence for scheduled RAID checks. @license GPL-3.0-only */

/** Keep array names constrained before composing sysfs paths or shell args. */
function pmssMdadmCheckarrayArrayNameIsSafe(string $array): bool
{
    return preg_match('/\Amd[0-9]+\z/', $array) === 1;
}

/** Prefer valid sysfs state; missing or malformed data falls back to mdstat. */
function pmssMdadmCheckarrayEntryDegradedState(array $entry, string $sysBlockRoot): ?bool
{
    $array = (string) ($entry['array'] ?? '');
    if (pmssMdadmCheckarrayArrayNameIsSafe($array) && !pmssFilesystemPathHasNulByte($sysBlockRoot)) {
        $raw = @file_get_contents(rtrim($sysBlockRoot, '/').'/'.$array.'/md/degraded');
        if (is_string($raw) && ctype_digit(trim($raw))) {
            return (int) trim($raw) > 0;
        }
    }

    // Preserve unknown state when neither source can decide.
    return pmssStorageHealthRaidDetailDegradedState((string) ($entry['detail'] ?? ''));
}

/**
 * Build the array selection plan for one checkarray run.
 *
 * @return array{fallback_all:bool,reason:string,healthy:array<int,string>,degraded:array<int,string>,unknown:array<int,string>}
 */
function pmssMdadmCheckarrayPlan(string $mdstatPath, string $sysBlockRoot): array
{
    $plan = ['fallback_all' => false, 'reason' => '', 'healthy' => [], 'degraded' => [], 'unknown' => []];
    // Malformed paths follow the same fallback as an unreadable mdstat file.
    $mdstat = $mdstatPath === '' || pmssFilesystemPathHasNulByte($mdstatPath)
        ? false : @file_get_contents($mdstatPath);
    $entries = is_string($mdstat) ? pmssStorageHealthRaidEntriesParse($mdstat, date('c')) : [];
    // Distinguish an empty host from array records the parser cannot enumerate.
    $plan['reason'] = !is_string($mdstat) ? 'mdstat_unreadable'
        : (empty($entries) && preg_match('/^md\d+\s*:/m', $mdstat) === 1 ? 'mdstat_parse_empty' : '');
    $plan['fallback_all'] = $plan['reason'] !== '';

    foreach ($entries as $entry) {
        $array = (string) ($entry['array'] ?? '');
        if (!pmssMdadmCheckarrayArrayNameIsSafe($array)) {
            continue;
        }

        $state = pmssMdadmCheckarrayEntryDegradedState($entry, $sysBlockRoot);
        $plan[$state === true ? 'degraded' : ($state === false ? 'healthy' : 'unknown')][] = $array;
    }

    return $plan;
}

