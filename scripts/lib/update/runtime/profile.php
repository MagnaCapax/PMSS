<?php
/**
 * Profiling helpers for `update-step2.php` orchestration.
 *
 * Collects per-step timing/return-code metadata emitted by `runStep()` so the
 * orchestrator can stream JSON events, log human-readable summaries, and stash
 * full traces to disk for later debugging when updates behave oddly or run
 * slower than expected.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logging.php';

/**
 * Ensure the global step profile buffer exists.
 */
function pmssInitProfileStore(): void
{
    if (!is_array($GLOBALS['PMSS_PROFILE'] ?? null)) {
        $GLOBALS['PMSS_PROFILE'] = [];
    }
}

/**
 * Track a single step execution in memory and JSON logs.
 */
function pmssRecordProfile(array $entry): void
{
    pmssInitProfileStore();
    $GLOBALS['PMSS_PROFILE'][] = $entry;
    pmssLogJson(['event' => 'step', 'data' => $entry]);
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
    $profile = $GLOBALS['PMSS_PROFILE'] ?? [];
    if (empty($profile)) {
        return;
    }

    // Summarise statuses so operators can see whether any ERR/SKIP steps
    // occurred without scanning the entire log.
    $counts = [
        'OK'   => 0,
        'ERR'  => 0,
        'SKIP' => 0,
        'OTHER'=> 0,
    ];
    foreach ($profile as $entry) {
        $status = strtoupper((string) ($entry['status'] ?? ''));
        $counts[isset($counts[$status]) ? $status : 'OTHER']++;
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
    if (!is_dir($profileDir = dirname($profileOutput))) {
        @mkdir($profileDir, 0755, true);
    }
    @file_put_contents($profileOutput, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
