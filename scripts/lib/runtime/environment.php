<?php
/**
 * Scalar, environment, and command-discovery helpers for the runtime facade.
 *
 * Loaded only through `scripts/lib/runtime.php`; the facade keeps caller
 * includes stable while this module owns pure value transforms.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssStatsCompareTimesBuild(?int $now = null): array { $now = $now ?? time(); return ['month' => $now - (30 * 24 * 60 * 60), 'week' => $now - (7 * 24 * 60 * 60), 'day' => $now - (24 * 60 * 60), 'hour' => $now - (60 * 60), '15min' => $now - (15 * 60)]; }
function pmssCommandBinaryNameIsSafe(string $binary): bool { return preg_match('/^[A-Za-z0-9._+-]+$/', $binary) === 1; }
function pmssCommandPathCandidateIsSafe(string $path): bool { return $path !== '' && strpos($path, '/') === 0 && strpbrk($path, "\0\r\n") === false; }
function pmssBlockDeviceNameIsDataDevice(string $device): bool { return preg_match(PMSS_BLOCK_DATA_DEVICE_NAME_PATTERN, $device) === 1; }

function pmssCommandPath(string $binary): string
{
    $binary = trim($binary);
    if ($binary === '' || !pmssCommandBinaryNameIsSafe($binary)) return '';
    $resolved = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');
    if (!is_string($resolved)) return '';
    $path = trim($resolved);
    return pmssCommandPathCandidateIsSafe($path) && is_executable($path) ? $path : '';
}

function pmssCommandArgvShellQuote(array $argv): string { return implode(' ', array_map(static function ($arg): string { return escapeshellarg((string) $arg); }, $argv)); }

/**
 * Build a shell-safe argv-style command string from a program and arguments.
 */
function pmssBuildCommand(string $program, array $args = []): string
{
    $prog = escapeshellcmd($program);
    return empty($args) ? $prog : $prog.' '.pmssCommandArgvShellQuote($args);
}

function pmssBuildUserShellCommand(string $username, string $command, string $shell = ''): string { return $shell === '' ? 'su '.escapeshellarg($username).' -c '.escapeshellarg($command) : 'su -s '.escapeshellarg($shell).' -c '.escapeshellarg($command).' '.escapeshellarg($username); }

/** Run the canonical direct-I/O 4 KiB latency probe and return raw output. */
function pmssIopingProbeOutput(?string $target): ?string
{
    $bin = pmssCommandPath('ioping');
    if ($bin === '' || $target === null || trim($target) === '') return null;
    $result = pmssCommandCapture(pmssCommandArgvShellQuote([$bin, '-c', (string) PMSS_IOPING_PROBE_COUNT, '-i', PMSS_IOPING_PROBE_INTERVAL, '-D', $target]), PMSS_IOPING_PROBE_TIMEOUT);
    $out = trim($result['stdout'] !== '' ? $result['stdout'] : $result['stderr']);
    return $out === '' ? null : $out;
}

function pmssIopingValueToMs(float $value, string $unit): float { return strtolower($unit) === 'us' ? $value / 1000.0 : (strtolower($unit) === 's' ? $value * 1000.0 : $value); }
function pmssIopingAverageMsFromOutput(string $output): ?float { return preg_match('/min\/avg\/max\/mdev\s*=\s*[^\/]+\/\s*([0-9.]+)\s*(us|ms|s)\s*\//i', $output, $m) ? pmssIopingValueToMs((float) $m[1], $m[2]) : null; }

/** Parse per-request ioping samples, ignoring summary lines. */
function pmssIopingSamplesMsFromOutput(string $output): array
{
    preg_match_all('/\btime\s*=\s*([0-9.]+)\s*(us|ms|s)\b/i', $output, $matches, PREG_SET_ORDER);
    return array_map(static function (array $match): float {
        return pmssIopingValueToMs((float) $match[1], $match[2]);
    }, $matches);
}

function pmssIopingMedianMsFromSamples(array $values): ?float
{
    if (empty($values)) return null;
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $mid = (int) floor(($count - 1) / 2);
    return $count % 2 === 1 ? (float) $values[$mid] : ((float) $values[$mid] + (float) $values[$mid + 1]) / 2.0;
}

function pmssIopingAverageMs(?string $target): ?float
{
    $out = pmssIopingProbeOutput($target);
    return $out === null ? null : pmssIopingAverageMsFromOutput($out);
}

function pmssIopingMedianMs(?string $target): ?float
{
    $out = pmssIopingProbeOutput($target);
    if ($out === null) return null;
    $median = pmssIopingMedianMsFromSamples(pmssIopingSamplesMsFromOutput($out));
    return $median !== null ? $median : pmssIopingAverageMsFromOutput($out);
}

function pmssEnvValueNormalized($value): string { return strtolower(trim((string) $value)); }
function pmssValueMatchesNormalized($value, array $tokens): bool { return in_array(pmssEnvValueNormalized($value), $tokens, true); }
function pmssEnvValueIsFalsey($value): bool { return pmssValueMatchesNormalized($value, ['', '0', 'false', 'no']); }
function pmssEnvValueIsTruthy($value): bool { return pmssValueMatchesNormalized($value, ['1', 'true', 'yes', 'on']); }
function pmssEnvFlagEnabled(string $envKey): bool { return getenv($envKey) === '1'; }
function pmssTestModeEnabled(): bool { return (defined('PMSS_TEST_MODE') && pmssEnvValueIsTruthy(constant('PMSS_TEST_MODE'))) || pmssEnvValueIsTruthy(getenv('PMSS_TEST_MODE')); }
function pmssNonEmptyStrings(array $values): array { return array_values(array_filter($values, 'strlen')); }

function pmssParseSizeToBytes(string $value, bool $wholeNumberOnly = false, bool $allowBareBinarySuffix = false): ?float
{
    $value = trim($value);
    $numberPattern = $wholeNumberOnly ? '[0-9]+' : '[0-9]+(?:\.[0-9]+)?';
    $suffixPattern = $allowBareBinarySuffix ? '(?:i?B?)?' : '(?:i?B)?';
    if ($value === '' || preg_match('/^('.$numberPattern.')\s*([KMGTPE]?)'.$suffixPattern.'$/i', $value, $matches) !== 1) return null;
    $powers = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5, 'E' => 6];
    return (float) $matches[1] * pow(1024, $powers[strtoupper($matches[2])]);
}

function pmssParseSizeToMiB($value): ?int
{
    $raw = trim((string) $value);
    if ($raw === '' || $raw === 'infinity' || $raw === '0') return null;
    if (preg_match('/^[0-9.]+\s*[KMG]?B?$/i', $raw) !== 1) return is_numeric($raw) ? (int) round(((float) $raw) / 1048576) : null;
    $bytes = pmssParseSizeToBytes($raw);
    return $bytes !== null ? (int) round($bytes / 1048576) : null;
}

function pmssConfigLineTrimmed(string $line, array $commentPrefixes = ['#']): string
{
    $trimmed = trim($line);
    foreach ($commentPrefixes as $prefix) if ($trimmed !== '' && $prefix !== '' && strpos($trimmed, $prefix) === 0) return '';
    return $trimmed;
}

function pmssConfigLineColumns(string $line, int $minColumns = 0, array $commentPrefixes = ['#']): array
{
    $trimmed = pmssConfigLineTrimmed($line, $commentPrefixes);
    if ($trimmed === '') return [];
    $columns = preg_split('/\s+/', $trimmed);
    return is_array($columns) && count($columns) >= $minColumns ? $columns : [];
}

function pmssConfigOptionsUpdatePlan(string $optionList, array $requiredOptions = [], array $removeOptions = [], bool $dropDefaultsOnly = false): array
{
    $options = pmssNonEmptyStrings(explode(',', $optionList));
    if ($dropDefaultsOnly && $options === ['defaults']) $options = [];
    $removed = [];
    foreach ($removeOptions as $removeOption) {
        $index = array_search($removeOption, $options, true);
        if ($index === false) continue;
        unset($options[$index]);
        $removed[] = $removeOption;
    }
    $options = array_values($options);
    $added = array_values(array_diff($requiredOptions, $options));
    return ['options' => array_merge($options, $added), 'added' => $added, 'removed' => $removed];
}

function pmssLogDir(): string { return pmssResolvePathFromEnv('PMSS_LOG_DIR', PMSS_LOG_DIR_DEFAULT); }
function pmssRuntimeDir(): string { return pmssResolvePathFromEnv('PMSS_RUNTIME_DIR', PMSS_RUNTIME_DIR_DEFAULT); }
function pmssStateDir(): string { return pmssResolvePathFromEnv('PMSS_STATE_DIR', PMSS_STATE_DIR_DEFAULT); }
