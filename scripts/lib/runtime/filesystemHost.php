<?php
/** Host memory, hostname, and network-port value interpretation. @license GPL-3.0-only */

function pmssProcMeminfoFieldsRead(string $path = '/proc/meminfo'): array
{
    $fields = [];
    foreach (pmssReadRegularFileNonEmptyLines($path) as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches) !== 1) continue;
        // An oversized sample cannot prove available memory; retain missing-field fallbacks.
        $value = pmssUnsignedDecimalIntParse($matches[2]);
        if ($value !== null) $fields[$matches[1]] = $value;
    }
    return $fields;
}

function pmssProcMeminfoTotalMiBRead(string $path = '/proc/meminfo'): int { $fields = pmssProcMeminfoFieldsRead($path); return (int) round(($fields['MemTotal'] ?? 0) / 1024); }

function pmssHostnameRead(string $default = '', string $path = '/etc/hostname'): string { return !pmssFilesystemPathHasNulByte($path) && is_string($hostname = @file_get_contents($path)) ? trim($hostname) : $default; }

function pmssHostnameIsValid(string $hostname, bool $allowIpv4 = true): bool
{
    if ($hostname === '' || preg_match('/\s/', $hostname) || strlen($hostname) > 253) return false;
    if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return $allowIpv4;
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $hostname) || strpos($hostname, '..') !== false || $hostname[0] === '.' || substr($hostname, -1) === '.') return false;
    foreach (explode('.', $hostname) as $label) if ($label === '' || strlen($label) > 63 || $label[0] === '-' || substr($label, -1) === '-') return false;
    return true;
}

function pmssNetworkPortInRange(int $port, int $min = 1, int $max = 65535): bool { return $min >= 1 && $max <= 65535 && $min <= $max && $port >= $min && $port <= $max; }

function pmssNetworkPortParseDigits($value, int $min = 1, int $max = 65535): ?int
{
    // Reject NUL before trim() can turn malformed port data into valid digits.
    if ((!is_int($value) && !is_string($value)) || (is_string($value) && strpos($value, "\0") !== false)) return null;
    $raw = trim((string) $value);
    $port = pmssUnsignedDecimalIntParse($raw);
    return $port !== null && pmssNetworkPortInRange($port, $min, $max) ? $port : null;
}

function pmssReadRegularFileNetworkPort(string $path, int $min = 1, int $max = 65535): ?int
{
    $raw = pmssReadRegularFileContents($path);
    return $raw === null ? null : pmssNetworkPortParseDigits($raw, $min, $max);
}
