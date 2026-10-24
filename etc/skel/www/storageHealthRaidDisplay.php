<?php
/** Pure RAID activity parsing and HTML, shared by the panel and root-owned skeleton readers. */

if (!function_exists('pmssStorageHealthRaidActivitySummaryParse')) {
/**
 * Parse mdadm activity details from /proc/mdstat.
 *
 * @return array<string,string>
 */
function pmssStorageHealthRaidActivitySummaryParse(string $activityLine): array
{
    $summary = [];
    foreach (['operation' => '/\b(check|resync|recovery|reshape)\b/', 'progress' => '/=\s*([0-9.]+%)/', 'eta' => '/\bfinish=([^\s]+)/', 'speed' => '/\bspeed=([^\s]+)/'] as $key => $pattern) {
        $summary[$key] = preg_match($pattern, $activityLine, $matches) === 1 ? $matches[1] : '';
    }
    return $summary;
}
}

if (!function_exists('pmssStorageHealthHomeRaidNoticeHtmlBuild')) {
/** Build the shared GUI notice for /home RAID maintenance. */
function pmssStorageHealthHomeRaidNoticeHtmlBuild($activity): string
{
    if (!is_array($activity) || empty($activity['array'])) {
        return '';
    }
    $flags = (array) ($activity['flags'] ?? []);
    if (in_array('degraded', $flags, true) && empty($activity['operation'])) {
        return <<<HTML
<div class="pmss-raid-notice pmss-raid-notice-error" role="alert" aria-live="assertive">
    <strong><span class="pmss-raid-icon" aria-hidden="true">&#9940;</span> Storage array degraded</strong>
    <p>A drive in your server's storage array has failed. Your data is still accessible but the array is running without full redundancy. Please contact support if you experience issues.</p>
</div>
HTML;
    }
    if (empty($activity['operation'])) {
        return '';
    }

    $parts = [];
    foreach (['progress' => 'Progress', 'eta' => 'ETA', 'speed' => 'Speed'] as $key => $label) {
        if (!empty($activity[$key])) {
            $parts[] = $label.': '.htmlspecialchars((string) $activity[$key], ENT_QUOTES, 'UTF-8');
        }
    }
    $detailHtml = empty($parts) ? '' : '<div class="pmss-raid-meta">'.implode(' <span aria-hidden="true">&bull;</span> ', $parts).'</div>';
    $operation = htmlspecialchars((string) $activity['operation'], ENT_QUOTES, 'UTF-8');
    $arrayName = htmlspecialchars((string) $activity['array'], ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div class="pmss-raid-notice" role="status" aria-live="polite">
    <strong><span class="pmss-raid-icon" aria-hidden="true">&#10071;</span> Home storage maintenance in progress</strong>
    <p>The <code>/home</code> RAID array {$arrayName} is running a {$operation}. Disk performance is temporarily lower.</p>
    {$detailHtml}
    <p>If possible, avoid heavy disk activity until the array work completes. Lighter use helps the maintenance finish sooner.</p>
</div>
HTML;
}
}

