<?php
/** Deluge upload-cap comparison and root-owned application records.
 * @license GPL-3.0-only
 */
require_once __DIR__.'/delugeManagedConfig.php';
require_once __DIR__.'/traffic.php';

/** Keep ownership evidence outside account-controlled homes (ADR 0213). */
function pmssDelugeUploadThrottleRecordPath(string $username): string
{
    return pmssIntegerSettingRuntimeUserPath('delugeUploadThrottle', $username);
}

/** Record before publishing a cap so interrupted writes can be retried safely. */
function pmssDelugeUploadThrottleRecordWrite(string $username, int $throttle): bool
{
    if (!pmssValidateUsername($username) || $throttle <= 0) return false;
    $path = pmssDelugeUploadThrottleRecordPath($username);
    if (pmssIntegerSettingStorageDirEnsure(dirname($path), 0700)
        && pmssIntegerSettingTargetModesPersist([$path => 0600], $throttle)) return true;
    pmssLogStatus('WARN', 'Failed to record Deluge applied upload throttle', 1);
    return false;
}

/** Numeric equality avoids Deluge's integer/float save loop; release only our last cap. */
function pmssDelugeUploadThrottleConfig(string $raw, ?int $throttle, int $applied = 0): ?string
{
    return pmssDelugeConfigTransform($raw, static function (array $config) use ($throttle, $applied): array {
        $current = $config['max_upload_speed'] ?? null;
        // Normalize only representable whole floats; never round a cap comparison.
        if (is_float($current) && $current > 0 && $current < PHP_INT_MAX && floor($current) === $current) {
            $current = (int) $current;
        }
        if (($throttle ?? 0) > 0) {
            if ($current !== $throttle) $config['max_upload_speed'] = $throttle;
        } elseif ($applied > 0 && $current === $applied) {
            $config['max_upload_speed'] = -1.0;
        }
        return $config;
    });
}

/** Inspect drift without touching daemon-owned configuration or the applied record. */
function pmssDelugeUploadThrottleNeedsUpdate(string $username): bool
{
    if (!pmssValidateUsername($username)) return false;
    $throttle = pmssReadTorrentThrottle($username);
    $applied = pmssIntegerSettingFileReadOrNull(pmssDelugeUploadThrottleRecordPath($username), 'pmssUnsignedDecimalIntParse');
    if ($applied === null || (($throttle ?? 0) <= 0 && $applied === 0)) return false;
    $raw = pmssReadRegularFileContents(pmssDelugeConfigPath($username));
    return $raw !== null && pmssDelugeUploadThrottleConfig($raw, $throttle, $applied) !== null;
}
