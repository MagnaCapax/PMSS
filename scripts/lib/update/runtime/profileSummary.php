<?php
/** Profile normalization, summary output and guarded report persistence; loaded by profile.php.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Normalize one profile row before summary sorting and JSON persistence.
 */
function pmssProfileSummaryEntry(array $entry): array
{
    return array(
        'description'    => pmssLogScalarText($entry['description'] ?? null, 'unknown'),
        'command'        => pmssLogScalarText($entry['command'] ?? null),
        'status'         => strtoupper(pmssLogScalarText($entry['status'] ?? null, 'OTHER')),
        'rc'             => is_numeric($entry['rc'] ?? null) ? (int) $entry['rc'] : 0,
        'duration'       => is_numeric($entry['duration'] ?? null) ? round(max(0.0, (float) $entry['duration']), 4) : 0.0,
        'dry_run'        => (bool) ($entry['dry_run'] ?? false),
        'stdout_excerpt' => pmssLogScalarText($entry['stdout_excerpt'] ?? null),
        'stderr_excerpt' => pmssLogScalarText($entry['stderr_excerpt'] ?? null),
    );
}

/**
 * Emit a short summary of the slowest steps and persist full traces.
 *
 * Logs:
 *  - status counts (OK/ERR/SKIP/other) for quick at-a-glance health.
 *  - top 5 steps by duration, including status and rc.
 * Persists the full step list to PMSS_PROFILE_OUTPUT or
 * (<PMSS_JSON_LOG>.profile.json) when configured.
 */
function pmssProfileSummary(): void
{
    if (!is_array($GLOBALS['PMSS_PROFILE'] ?? null)) {
        return;
    }

    $profile = array();
    // Normalize and count together, before sorting changes the report order.
    $counts = array_fill_keys(['OK', 'ERR', 'SKIP', 'OTHER'], 0);
    foreach ($GLOBALS['PMSS_PROFILE'] as $entry) {
        if (!is_array($entry)) continue;
        $entry = pmssProfileSummaryEntry($entry);
        $profile[] = $entry;
        ++$counts[isset($counts[$entry['status']]) ? $entry['status'] : 'OTHER'];
    }
    if (empty($profile)) {
        return;
    }

    logmsg(sprintf(
        'Step status summary: %d OK, %d ERR, %d SKIP, %d other',
        $counts['OK'],
        $counts['ERR'],
        $counts['SKIP'],
        $counts['OTHER']
    ));

    usort($profile, static function ($a, $b) {
        return $b['duration'] <=> $a['duration'];
    });
    $topSteps = array_slice($profile, 0, 5);
    logmsg('Step duration summary (top 5):');
    foreach ($topSteps as $entry) {
        logmsg(sprintf(
            '  - %s [%s %.3fs rc=%d]',
            $entry['description'],
            $entry['status'],
            $entry['duration'],
            $entry['rc']
        ));
    }
    pmssLogJson([
        'event'         => 'profile_summary',
        'steps'         => $topSteps,
        'status_counts' => $counts,
    ]);

    $profileOutput = getenv('PMSS_PROFILE_OUTPUT')
        ?: (($jsonLogPath = (getenv('PMSS_JSON_LOG') ?: '')) !== '' ? $jsonLogPath.'.profile.json' : '');
    if ($profileOutput === '') {
        return;
    }
    // Encoding must succeed before opening the report: an empty fallback erases prior evidence.
    $encoded = pmssJsonEncodePretty($profile);
    // An override must not redirect a root-run profile write through a symlink.
    if ($encoded === null) {
        return;
    }
    if (!pmssPathTargetIsSafe($profileOutput, false)) {
        logmsg('[WARN] Unable to write complete step profile report');
        return;
    }
    if (!pmssDirEnsureExists(dirname($profileOutput), 0755)) {
        return;
    }
    // A failed or short write must be visible; the summary remains best-effort.
    if (!pmssFileWriteComplete($profileOutput, $encoded)) {
        logmsg('[WARN] Unable to write complete step profile report');
    }
}
