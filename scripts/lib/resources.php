<?php
/**
 * Library helper: resources.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
pmssRequireRelativeFiles(__DIR__, ['lighttpd/userFileWrite.php', 'resources/payload.php', 'resources/logCodec.php']);

const PMSS_RESOURCE_LOG_TAIL_LINES_MAX = 10080;
const PMSS_RESOURCE_LOG_TAIL_TIMEOUT_SECONDS = 5;

/** Keep resource log file lookups on validated usernames only. */
function pmssResourceUserIsValid(string $user): bool
{
    return (function_exists('pmssNormalizeUsername') ? pmssNormalizeUsername($user) : strtolower(trim($user))) === $user
        && preg_match('/^[a-z0-9-]+$/', $user) === 1
        && ($user === 'www-data'
        || !function_exists('pmssValidateUsername')
        || pmssValidateUsername($user));
}

/** Resolve a safe per-user resource log path before shelling to tail. */
function pmssResourceLogFilePath(string $resourceDir, string $user): ?string
{
    if (!pmssResourceUserIsValid($user)) {
        return null;
    }

    $path = rtrim($resourceDir, '/').'/'.$user;
    return pmssPathTargetIsSafe($path, false, true) ? $path : null;
}

/** Keep resource tail calls bounded before they reach the shell. */
function pmssResourceTailLineCount($timePeriod): int { return max(1, min((int) $timePeriod, PMSS_RESOURCE_LOG_TAIL_LINES_MAX)); }

/**
 * Read and persist per-user resource statistics for PMSS hosts.
 */
class resourceStatistics
{
    /** @var string */
    private $resourceDir;

    public function __construct(array $paths = [])
    {
        $this->resourceDir = pmssDirPathResolve($paths['resource_dir'] ?? null, 'PMSS_RESOURCE_DIR', '/var/log/pmss/resources');
    }

    /**
     * Fetch resource log lines for a user from the PMSS log directory.
     *
     * @param string $user Username to load resource log entries for.
     * @param int $timePeriod Number of log lines to read from tail.
     * @return string Raw log text, possibly empty when no entries exist.
     */
    public function getData($user, $timePeriod = PMSS_RESOURCE_LOG_TAIL_LINES_MAX)
    {
        $path = pmssResourceLogFilePath($this->resourceDir, (string) $user);
        if ($path === null) {
            return '';
        }

        $tail = pmssCommandPath('tail');
        if ($tail === '') return '';
        $result = pmssCommandCapture(pmssCommandArgvShellQuote([$tail, '-n', (string) pmssResourceTailLineCount($timePeriod), $path]), PMSS_RESOURCE_LOG_TAIL_TIMEOUT_SECONDS);
        return $result['rc'] === 0 ? trim($result['stdout']) : '';
    }

    /**
     * Accumulate parsed log lines into the persisted resource payload schema.
     *
     * @param string $dataLines Newline-delimited resource log payload.
     * @param array<string, int> $compareTimes Window thresholds keyed by label.
     * @param callable|null $parseErrorLogger Optional parse-failure callback.
     * @return array<string, mixed>|null
     */
    public function collectWindowResultsFromData(string $dataLines, array $compareTimes, ?callable $parseErrorLogger = null): ?array
    {
        if ($compareTimes === []) return null;
        $resourceData = pmssNonEmptyStrings(explode("\n", trim($dataLines)));
        if (count($resourceData) < 2) return null;
        $threshold = (int) min($compareTimes);
        $accumulator = new ResourceStatsAccumulator($compareTimes);
        foreach ($resourceData as $line) {
            $parsed = $this->parseLine($line);
            if ($parsed === false) {
                $parseErrorLogger !== null && $parseErrorLogger($line);
                continue;
            }
            if ($parsed['timestamp'] < $threshold) {
                continue;
            }
            $accumulator->addSample($parsed);
        }

        return $accumulator->hasSamples() ? $accumulator->results() : null;
    }

    /**
     * Parse a single resource log line into structured numeric fields.
     *
     * @param string $thisLine Raw log line to parse.
     * @return array|false
     */
    public function parseLine($thisLine)
    {
        return pmssResourceLogLineParse($thisLine);
    }
}
