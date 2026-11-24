<?php
/**
 * Monthly I/O operations limit helpers.
 *
 * Persistence paths:
 * - /etc/seedbox/runtime/iopsLimits/<user> (consumed by scripts/cron/iopsLimits.php)
 * - /home/<user>/.iopsLimit (user-visible limit file)
 *
 * Resource usage source:
 * - /var/run/pmss/resourceStats/<user> (PMSS_RUNTIME_DIR overrides the runtime root)
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/integerSetting.php';

const PMSS_IOPS_LIMIT_MAX_MONTHLY_OPERATIONS = 1.0E14;
const PMSS_IOPS_LIMIT_SENTINEL_FLOOR = 9.0E18;

/** @param mixed $raw */
function pmssIopsLimitParseMonthlyOperations($raw, ?string &$error = null): ?int
{
    return pmssIntegerSettingParseNonNegative($raw, 'ops', $error);
}

function pmssIopsLimitRuntimePath(string $username, ?string $runtimeDir = null): string
{
    return pmssIntegerSettingRuntimeUserPath('iopsLimits', $username, $runtimeDir);
}

function pmssIopsLimitPath(string $username, ?string $homeDir = null): string
{
    return pmssUserHomeFilePath($username, '.iopsLimit', $homeDir);
}

/** @return array<string,int> */
function pmssIopsLimitTargetModes(string $username, ?string $homeDir = null, ?string $runtimeDir = null): array
{
    return [
        pmssIopsLimitRuntimePath($username, $runtimeDir) => 0600,
        pmssIopsLimitPath($username, $homeDir) => 0664,
    ];
}

/** @param mixed $raw */
function pmssIopsLimitMonthlyUsageValue($raw): float
{
    if (!is_numeric($raw)) {
        return 0.0;
    }

    $value = (float) $raw;
    return $value >= 0.0
        && $value <= PMSS_IOPS_LIMIT_MAX_MONTHLY_OPERATIONS
        && $value < PMSS_IOPS_LIMIT_SENTINEL_FLOOR
        ? $value
        : 0.0;
}

/** Sums read and write operations of one stats window (month = last 30 days, week = last 7 days). */
function pmssReadUserMonthlyIopsUsage(string $path, string $window = 'month'): int
{
    $data = pmssReadSerializedArrayFile($path);
    if ($data === null) {
        return 0;
    }

    $usage = 0.0;
    foreach (['io_read_ops', 'io_write_ops'] as $direction) {
        // Decoding an array does not guarantee that its nested counters are arrays.
        $counter = $data[$direction] ?? null;
        $raw = is_array($counter) ? ($counter['raw'] ?? null) : null;
        $usage += pmssIopsLimitMonthlyUsageValue(is_array($raw) ? ($raw[$window] ?? null) : null);
    }

    return (int) round($usage);
}

/** A budget is judged on the last 7 days scaled to 30, so a burst trips within days and a capped account can recover. */
const PMSS_IOPS_BUDGET_WINDOW_SCALE = 30 / 7;
/** An over-budget account keeps its read cap at least this long after the enforce time. */
const PMSS_IOPS_BUDGET_HOLD_SECONDS = 3 * 86400;
/** The held read cap never goes below this, whatever the budget; a whole ten, as blk-throttle delivers whole tens per 100 ms slice (pmssReadLimitDelivered). */
const PMSS_IOPS_BUDGET_READ_CAP_FLOOR = 30;

function pmssIopsBudgetUsage(string $statsPath): int
{
    return (int) round(pmssReadUserMonthlyIopsUsage($statsPath, 'week') * PMSS_IOPS_BUDGET_WINDOW_SCALE);
}

function pmssIopsBudgetMarkerPath(string $username, ?string $runtimeDir = null): string
{
    return pmssIntegerSettingRuntimeUserPath('iopsLimitEnforced', $username, $runtimeDir);
}

/** The enforce time stored in the marker; a marker without one counts from its mtime. Null = no marker. */
function pmssIopsBudgetMarkerTime(string $markerPath): ?int
{
    if (!pmssRegularFilePathIsReadable($markerPath)) {
        return null;
    }
    $raw = trim((string) pmssReadRegularFileContents($markerPath));
    if (ctype_digit($raw) && (int) $raw > 0) {
        return (int) $raw;
    }
    $mtime = @filemtime($markerPath);
    return $mtime === false ? 0 : (int) $mtime;
}

/** The budget's own average rate, never below the floor. */
function pmssIopsBudgetReadCap(int $monthlyLimit): int
{
    return max(PMSS_IOPS_BUDGET_READ_CAP_FLOOR, (int) round($monthlyLimit / (30 * 86400)));
}

/** Read cap for an account the hourly enforcer marked over budget; null when unmarked, unbudgeted or the kernel cannot hold read caps. */
function pmssIopsBudgetHeldReadCap(string $username, bool $kernelReady, ?string $runtimeDir = null): ?int
{
    if (!$kernelReady || pmssIopsBudgetMarkerTime(pmssIopsBudgetMarkerPath($username, $runtimeDir)) === null) {
        return null;
    }
    $limit = pmssIntegerSettingFileRead(pmssIopsLimitRuntimePath($username, $runtimeDir), 'pmssIopsLimitParseMonthlyOperations');
    return $limit > 0 ? pmssIopsBudgetReadCap($limit) : null;
}

/** Root-owned record of the held cap the cap writer put in the kernel, so a release takes back exactly that cap. */
function pmssIopsBudgetCapRecordPath(string $username, ?string $runtimeDir = null): string
{
    return pmssIntegerSettingRuntimeUserPath('iopsBudgetCap', $username, $runtimeDir);
}

function pmssIopsBudgetCapRecordParse(string $raw): ?int
{
    return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;
}

/** The recorded held cap; null when there is none or it cannot be read. */
function pmssIopsBudgetCapRecordRead(string $username, ?string $runtimeDir = null): ?int
{
    return pmssIntegerSettingFileParse(pmssIopsBudgetCapRecordPath($username, $runtimeDir), 'pmssIopsBudgetCapRecordParse');
}

/** A failed write only means a later release leaves the cap to the other cap sources, as before the record existed. */
function pmssIopsBudgetCapRecordWrite(string $username, int $cap, ?string $runtimeDir = null): bool
{
    if (pmssIopsBudgetCapRecordRead($username, $runtimeDir) === $cap) return true;
    $path = pmssIopsBudgetCapRecordPath($username, $runtimeDir);
    return pmssIntegerSettingStorageDirEnsure(dirname($path), 0700) && pmssAtomicWriteFile($path, (string) $cap, 0600);
}
