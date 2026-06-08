<?php
/**
 * Filesystem, record-reading, and host identity helpers for the runtime facade.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssDirEnsureExists(string $path, int $mode = 0755): bool { return $path !== '' && !pmssFilesystemPathHasNulByte($path) && (is_dir($path) || @mkdir($path, $mode, true) || is_dir($path)); }
function pmssPrivateTempPrefixIsSafe(string $prefix): bool { return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $prefix) === 1; }
function pmssCreatePrivateTempFile(string $prefix): ?string { return pmssPrivateTempPrefixIsSafe($prefix) && is_string($path = @tempnam(sys_get_temp_dir(), $prefix)) ? $path : null; }

function pmssCreatePrivateTempDir(string $prefix): ?string
{
    if (!pmssPrivateTempPrefixIsSafe($prefix)) { logMessage('[WARN] Refusing private temporary directory with unsafe prefix'); return null; }
    $path = pmssCreatePrivateTempFile($prefix);
    if ($path === null) return null;
    if (!@unlink($path) || !@mkdir($path, 0700)) {
        if (is_file($path) && !is_link($path)) @unlink($path);
        return null;
    }
    @chmod($path, 0700);
    if (pmssPrivateTempDirRealpath($path, $prefix) === null) {
        @rmdir($path);
        return null;
    }
    return $path;
}

function pmssPrivateTempDirRealpath(string $path, string $prefix, ?callable $logger = null): ?string
{
    $log = $logger ?: 'logMessage';
    if (!pmssPrivateTempPrefixIsSafe($prefix)) { $log('[WARN] Refusing temporary directory cleanup for unsafe prefix'); return null; }
    if (pmssFilesystemPathHasNulByte($path)) { $log('[WARN] Refusing temporary directory cleanup for unsafe path'); return null; }
    $base = realpath(sys_get_temp_dir()); $real = $path !== '' && !is_link($path) ? realpath($path) : false;
    if ($base === false || $base === DIRECTORY_SEPARATOR || $real === false || !is_dir($real)) { $log('[WARN] Refusing temporary directory cleanup for unresolved path: '.$path); return null; }
    $basePrefix = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    if (strpos($real, $basePrefix) !== 0 || strpos(basename($real), $prefix) !== 0) { $log('[WARN] Refusing temporary directory cleanup outside PMSS temp scope: '.$real); return null; }
    return $real;
}

// NUL bytes make PHP filesystem calls version-dependent; reject them at the
// runtime boundary and keep callers on the existing fail-soft path.
function pmssFilesystemPathHasNulByte(string $path): bool { return strpos($path, "\0") !== false; }
function pmssRegularFilePathIsReadable(string $path): bool { return $path !== '' && !pmssFilesystemPathHasNulByte($path) && is_file($path) && !is_link($path); }
function pmssReadRegularFileContents(string $path): ?string { return (!pmssRegularFilePathIsReadable($path) || !is_string($contents = @file_get_contents($path))) ? null : $contents; }
function pmssReadRegularFileTrimmed(string $path): ?string { return (($contents = pmssReadRegularFileContents($path)) === null) ? null : trim($contents); }

function pmssProcMeminfoFieldsRead(string $path = '/proc/meminfo'): array
{
    $fields = [];
    foreach (explode("\n", pmssReadRegularFileContents($path) ?? '') as $line) if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches) === 1) $fields[$matches[1]] = (int) $matches[2];
    return $fields;
}

function pmssProcMeminfoTotalMiBRead(string $path = '/proc/meminfo'): int { $fields = pmssProcMeminfoFieldsRead($path); return isset($fields['MemTotal']) ? (int) round($fields['MemTotal'] / 1024) : 0; }

function pmssReadSerializedArrayFile(string $path): ?array
{
    $raw = pmssReadRegularFileContents($path);
    if ($raw === null || $raw === '') return null;
    $data = @unserialize($raw, ['allowed_classes' => false]);
    return is_array($data) ? $data : null;
}

function pmssReadOptionalSerializedArrayFile(string $path, string $label = 'serialized array file'): array
{
    if (pmssFilesystemPathHasNulByte($path)) return [];
    if (!file_exists($path)) return [];
    $payload = pmssReadSerializedArrayFile($path);
    if ($payload === null) throw new RuntimeException('Invalid '.$label.': '.$path);
    return $payload;
}

function pmssReadRequiredRegularFile(string $path, string $label = 'required file'): string
{
    if (!pmssRegularFilePathIsReadable($path)) throw new RuntimeException('Missing '.$label.': '.$path);
    $contents = pmssReadRegularFileContents($path);
    if ($contents === null) throw new RuntimeException('Unable to read '.$label.': '.$path);
    return $contents;
}

function pmssReadRegularFileDigits(string $path): ?string { return (($raw = pmssReadRegularFileTrimmed($path)) !== null && $raw !== '' && ctype_digit($raw)) ? $raw : null; }
function pmssNetworkPortInRange(int $port, int $min = 1, int $max = 65535): bool { return $min >= 1 && $max <= 65535 && $min <= $max && $port >= $min && $port <= $max; }

function pmssNetworkPortParseDigits($value, int $min = 1, int $max = 65535): ?int
{
    if (!is_int($value) && !is_string($value)) return null;
    $raw = trim((string) $value);
    if ($raw === '' || !ctype_digit($raw)) return null;
    $port = (int) $raw;
    return pmssNetworkPortInRange($port, $min, $max) ? $port : null;
}

function pmssReadRegularFileNetworkPort(string $path, int $min = 1, int $max = 65535): ?int
{
    $raw = pmssReadRegularFileDigits($path);
    return $raw === null ? null : pmssNetworkPortParseDigits($raw, $min, $max);
}

function pmssColonRecordFieldsLookup(string $path, string $recordName, int $minFields = 2, bool $skipEmptyLines = true): ?array
{
    if ($recordName === '' || $minFields < 1 || strpos($recordName, ':') !== false || preg_match('/[\r\n\0\/]/', $recordName) === 1 || !pmssRegularFilePathIsReadable($path)) return null;
    $flags = FILE_IGNORE_NEW_LINES | ($skipEmptyLines ? FILE_SKIP_EMPTY_LINES : 0);
    $lines = @file($path, $flags);
    if (!is_array($lines)) return null;
    $prefix = $recordName.':';
    foreach ($lines as $line) {
        if (!is_string($line) || strpos($line, $prefix) !== 0) continue;
        $fields = explode(':', $line);
        return count($fields) >= $minFields ? $fields : null;
    }
    return null;
}

function pmssReadRegularFileInt(string $path, int $default = 0): int { $raw = pmssReadRegularFileDigits($path); return $raw === null ? $default : (int) $raw; }
function pmssHostnameRead(string $default = '', string $path = '/etc/hostname'): string { return !pmssFilesystemPathHasNulByte($path) && is_string($hostname = @file_get_contents($path)) ? trim($hostname) : $default; }

function pmssHostnameIsValid(string $hostname, bool $allowIpv4 = true): bool
{
    if ($hostname === '' || preg_match('/\s/', $hostname) || strlen($hostname) > 253) return false;
    if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return $allowIpv4;
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $hostname) || strpos($hostname, '..') !== false || $hostname[0] === '.' || substr($hostname, -1) === '.') return false;
    foreach (explode('.', $hostname) as $label) if ($label === '' || strlen($label) > 63 || $label[0] === '-' || substr($label, -1) === '-') return false;
    return true;
}
