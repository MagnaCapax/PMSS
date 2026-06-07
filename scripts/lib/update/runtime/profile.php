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
require_once __DIR__.'/../../runtime.php';
require_once __DIR__.'/stepPolicy.php';

/**
 * Track a single step execution in memory and JSON logs.
 */
function pmssRecordProfile(array $entry): void
{
    if (!is_array($GLOBALS['PMSS_PROFILE'] ?? null)) {
        $GLOBALS['PMSS_PROFILE'] = [];
    }
    $GLOBALS['PMSS_PROFILE'][] = $entry;
    pmssLogJson(['event' => 'step', 'data' => $entry]);
}

/**
 * Run non-shell orchestration work with profiling metadata.
 *
 * Wrapper keeps phase-2 profiling complete even when a step is pure PHP and
 * does not invoke runStep() directly.
 *
 * @param callable $step
 * @return mixed
 */
function pmssRunProfiledStep(string $description, callable $step)
{
    $started = microtime(true);
    logmsg('[START] '.$description.' :: [callable]');

    try {
        $result = $step();
    } catch (\Throwable $throwable) {
        $duration = microtime(true) - $started;
        pmssLogStatus('ERR', $description, 1, $duration);
        throw $throwable;
    }

    $duration = microtime(true) - $started;
    pmssLogStatus('OK', $description, 0, $duration);

    return $result;
}

/**
 * Profile a direct function/method invocation with optional arguments.
 *
 * @param callable $callable
 * @param array<int, mixed> $arguments
 * @return mixed
 */
function pmssRunProfiledCallable(string $description, callable $callable, array $arguments = [], string $classification = '')
{
    try {
        return pmssRunProfiledStep($description, static function () use ($callable, $arguments) { return $callable(...$arguments); });
    } catch (\Throwable $throwable) {
        if ($classification === '') {
            throw $throwable;
        }
        $reason = get_class($throwable).($throwable->getMessage() !== '' ? ': '.$throwable->getMessage() : '');
        pmssUpdateStep2HandleClassifiedFailure($description, $classification, 1, $reason);
    }
}

/**
 * Run a table of profiled callables in order.
 *
 * @param array<int,array{0:string,1:callable,2?:array<int,mixed>,3?:string}> $steps
 */
function pmssRunProfiledCallableBatch(array $steps): void
{
    foreach ($steps as $step) {
        $description = (string) $step[0];
        $arguments = isset($step[2]) && is_array($step[2]) ? $step[2] : [];
        $classification = isset($step[3]) ? (string) $step[3] : '';
        pmssRunProfiledCallable($description, $step[1], $arguments, $classification);
    }
}

/**
 * Convert profile fields to log-safe scalar text.
 */
function pmssProfileScalarText($value, string $default = ''): string
{
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        $text = $value ? 'true' : 'false';
    } elseif (is_scalar($value)) {
        $text = (string) $value;
    } elseif (is_object($value) && method_exists($value, '__toString')) {
        $text = (string) $value;
    } else {
        $text = gettype($value);
    }

    $text = str_replace(array("\r", "\n", "\t", "\0"), ' ', $text);
    $text = preg_replace('/[[:cntrl:]]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', trim((string) $text));

    return is_string($text) && $text !== '' ? $text : $default;
}

/**
 * Normalize one profile row before summary sorting and JSON persistence.
 */
function pmssProfileSummaryEntry(array $entry): array
{
    return array(
        'description'    => pmssProfileScalarText($entry['description'] ?? null, 'unknown'),
        'command'        => pmssProfileScalarText($entry['command'] ?? null),
        'status'         => strtoupper(pmssProfileScalarText($entry['status'] ?? null, 'OTHER')),
        'rc'             => is_numeric($entry['rc'] ?? null) ? (int) $entry['rc'] : 0,
        'duration'       => is_numeric($entry['duration'] ?? null) ? round(max(0.0, (float) $entry['duration']), 4) : 0.0,
        'dry_run'        => (bool) ($entry['dry_run'] ?? false),
        'stdout_excerpt' => pmssProfileScalarText($entry['stdout_excerpt'] ?? null),
        'stderr_excerpt' => pmssProfileScalarText($entry['stderr_excerpt'] ?? null),
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
    $rawProfile = $GLOBALS['PMSS_PROFILE'] ?? [];
    if (empty($rawProfile) || !is_array($rawProfile)) {
        return;
    }

    $profile = array();
    foreach ($rawProfile as $entry) {
        if (is_array($entry)) {
            $profile[] = pmssProfileSummaryEntry($entry);
        }
    }
    if (empty($profile)) {
        return;
    }

    // Summarise statuses so operators can see whether any ERR/SKIP steps
    // occurred without scanning the entire log.
    $counts = array_fill_keys(['OK', 'ERR', 'SKIP', 'OTHER'], 0);
    foreach ($profile as $entry) {
        $status = strtoupper((string) ($entry['status'] ?? ''));
        ++$counts[isset($counts[$status]) ? $status : 'OTHER'];
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
    pmssDirEnsureExists(dirname($profileOutput), 0755);
    @file_put_contents($profileOutput, pmssJsonEncodePretty($profile) ?? '');
}
