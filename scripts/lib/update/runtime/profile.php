<?php
/**
 * Profiling helpers for `update-step2.php` orchestration.
 *
 * Collects per-step timing/return-code metadata emitted by `runStep()` so the
 * orchestrator can stream JSON events, log human-readable summaries, and stash
 * full traces to disk for later debugging when updates behave oddly or run
 * slower than expected.
 */

require_once __DIR__.'/../logging.php';

if (!function_exists('pmssInitProfileStore')) {
    /**
     * Ensure the global step profile buffer exists.
     */
    function pmssInitProfileStore(): void
    {
        if (!isset($GLOBALS['PMSS_PROFILE']) || !is_array($GLOBALS['PMSS_PROFILE'])) {
            $GLOBALS['PMSS_PROFILE'] = [];
        }
    }
}

if (!function_exists('pmssRecordProfile')) {
    /**
     * Track a single step execution in memory and JSON logs.
     */
    function pmssRecordProfile(array $entry): void
    {
        pmssInitProfileStore();
        $GLOBALS['PMSS_PROFILE'][] = $entry;
        pmssLogJson(['event' => 'step', 'data' => $entry]);
    }
}

if (!function_exists('pmssProfileSummary')) {
    /**
     * Emit a short summary of the slowest steps and persist full traces.
     */
    function pmssProfileSummary(): void
    {
        $profile = $GLOBALS['PMSS_PROFILE'] ?? [];
        if (empty($profile)) {
            return;
        }
        usort($profile, static fn($a, $b) => $b['duration'] <=> $a['duration']);
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
        pmssLogJson(['event' => 'profile_summary', 'steps' => $topSteps]);

        $profileOutput = getenv('PMSS_PROFILE_OUTPUT') ?: '';
        if ($profileOutput === '') {
            $jsonLogPath = getenv('PMSS_JSON_LOG') ?: '';
            if ($jsonLogPath !== '') {
                $profileOutput = $jsonLogPath.'.profile.json';
            }
        }
        if ($profileOutput === '') {
            return;
        }
        $dir = dirname($profileOutput);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($profileOutput, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
