<?php
/** Environment defaults and the stable value/probe include. @license GPL-3.0-only */

// The dir helpers below resolve through pathSafety.php; load it so a direct require can call them.
require_once dirname(__DIR__).'/pathSafety.php';

// Defaults this module reads. They live here, not in the facade, so a direct require cannot
// call pmssLogDir() and friends before they exist (an update bootstrap fatal, 2026-10-23).
const PMSS_LOG_DIR_DEFAULT = '/var/log/pmss';
const PMSS_RUNTIME_DIR_DEFAULT = '/var/run/pmss';
const PMSS_STATE_DIR_DEFAULT = '/var/lib/pmss';
const PMSS_BLOCK_DATA_DEVICE_NAME_PATTERN = '/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/';

require_once __DIR__.'/values.php';
require_once __DIR__.'/config.php';
require_once __DIR__.'/commandLookup.php';
require_once __DIR__.'/commandShell.php';
require_once __DIR__.'/ioping.php';

function pmssStatsCompareTimesBuild(?int $now = null): array { $now = $now ?? time(); return ['month' => $now - (30 * 24 * 60 * 60), 'week' => $now - (7 * 24 * 60 * 60), 'day' => $now - (24 * 60 * 60), 'hour' => $now - (60 * 60), '15min' => $now - (15 * 60)]; }
function pmssBlockDeviceNameIsDataDevice(string $device): bool { return preg_match(PMSS_BLOCK_DATA_DEVICE_NAME_PATTERN, $device) === 1; }

/** Read a trimmed override; blank values use the default verbatim, while zero is retained. */
function pmssEnvTrimmed(string $envKey, string $default = ''): string
{
    $value = trim((string) getenv($envKey));
    return $value === '' ? $default : $value;
}

/** Read unsigned decimal overrides without trimming; callers own defaults and zero policy. */
function pmssEnvReadDigits(string $envKey): ?int { return (($value = getenv($envKey)) !== false && ctype_digit($value)) ? (int) $value : null; }

function pmssEnvValueNormalized($value): string { return strtolower(trim((string) $value)); }
function pmssValueMatchesNormalized($value, array $tokens): bool { return in_array(pmssEnvValueNormalized($value), $tokens, true); }
function pmssEnvValueIsFalsey($value): bool { return pmssValueMatchesNormalized($value, ['', '0', 'false', 'no']); }
function pmssEnvValueIsTruthy($value): bool { return pmssValueMatchesNormalized($value, ['1', 'true', 'yes', 'on']); }
function pmssEnvFlagEnabled(string $envKey): bool { return getenv($envKey) === '1'; }
function pmssTestModeEnabled(): bool { return (defined('PMSS_TEST_MODE') && pmssEnvValueIsTruthy(constant('PMSS_TEST_MODE'))) || pmssEnvValueIsTruthy(getenv('PMSS_TEST_MODE')); }

function pmssLogDir(): string { return pmssResolvePathFromEnv('PMSS_LOG_DIR', PMSS_LOG_DIR_DEFAULT); }
function pmssRuntimeDir(): string { return pmssResolvePathFromEnv('PMSS_RUNTIME_DIR', PMSS_RUNTIME_DIR_DEFAULT); }
function pmssStateDir(): string { return pmssResolvePathFromEnv('PMSS_STATE_DIR', PMSS_STATE_DIR_DEFAULT); }
