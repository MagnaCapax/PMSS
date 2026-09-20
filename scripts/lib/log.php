<?php
/**
 * Shared legacy logging entry point for PMSS libraries.
 *
 * Keeps `logmsg()` available without forcing callers to pull the full update
 * bootstrap, while still forwarding into structured update logging whenever
 * that stack has already been loaded.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/pathSafety.php';

/** Collapse whitespace without changing caller-owned trimming or byte limits. */
function pmssLogWhitespaceCollapse(string $text): string { return (string) preg_replace('/\s+/', ' ', $text); }

/** Replace control-character runs while retaining printable column spacing. */
function pmssLogControlCharactersReplace(string $text): string { return (string) preg_replace('/[[:cntrl:]]+/', ' ', $text); }

/** Convert arbitrary log fields to single-line text, defaulting only when empty. */
function pmssLogScalarText($value, string $default = ''): string
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
    $text = pmssLogWhitespaceCollapse(trim(pmssLogControlCharactersReplace($text)));
    return $text !== '' ? $text : $default;
}

if (!function_exists('logmsg')) {
    /** Historical logging function retained for backwards compatibility. */
    function logmsg(string $message): void
    {
        if (!empty($GLOBALS['PMSS_LOGMSG_USES_LOGMESSAGE']) && function_exists('logMessage')) {
            logMessage($message);
            return;
        }

        $defaults = is_array($GLOBALS['PMSS_LOGMSG_DEFAULTS'] ?? null) ? $GLOBALS['PMSS_LOGMSG_DEFAULTS'] : [];
        $script = trim((string) ($defaults['script'] ?? '')) ?: ($_SERVER['SCRIPT_NAME'] ?? __FILE__);
        $baseName = trim((string) ($defaults['base_name'] ?? '')) ?: basename($script, '.php');
        $primary = rtrim(trim((string) ($defaults['dir'] ?? '')) !== '' ? (string) $defaults['dir'] : '/var/log/pmss', '/').'/'.$baseName.'.log';
        $fallback = rtrim(trim((string) ($defaults['fallback_dir'] ?? '')) !== '' ? (string) $defaults['fallback_dir'] : '/tmp', '/').'/'.$baseName.'.log';
        pmssLogWriteMessage($primary, $fallback, $message, !empty($defaults['write_to_stderr']));
    }
}

/**
 * Encode a payload as JSON while tolerating invalid UTF-8.
 */
function pmssJsonEncodeSafe(array $payload, int $flags = 0): ?string
{
    $encoded = json_encode($payload, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($encoded) ? $encoded : null;
}

/** Decode JSON through associative arrays, rejecting invalid or scalar payloads. */
function pmssJsonDecodeAssoc(string $payload): ?array { $decoded = json_decode($payload, true); return is_array($decoded) ? $decoded : null; }

/** Encode data with PMSS's standard pretty file-output flags. */
function pmssJsonEncodePretty($payload, int $extraFlags = 0): ?string { $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | $extraFlags); return is_string($encoded) ? $encoded : null; }
/** Encode pretty JSON with the newline expected by PMSS file writers. */
function pmssJsonEncodePrettyLine($payload, int $extraFlags = 0): ?string { $encoded = pmssJsonEncodePretty($payload, $extraFlags); return is_string($encoded) ? $encoded.PHP_EOL : null; }

/** Read a JSON object file as an associative array, rejecting unsafe paths when requested. */
function pmssJsonFileReadAssoc(string $path, bool $safePathRequired = false): ?array
{
    if ($path === '' || ($safePathRequired && !pmssPathTargetIsSafe($path, false, true)) || !is_file($path) || is_link($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return pmssJsonDecodeAssoc($raw);
}

/** Validate a log write target before appending data. */
function pmssLogWritePathIsSafe(string $path): bool
{
    // Check raw bytes before trim() can hide a malformed filesystem target.
    if (preg_match('/[\r\n\0]/', $path) === 1) {
        return false;
    }
    $path = trim($path);
    if ($path === '') {
        return false;
    }

    if (!pmssPathSegmentsAreSafe($path, true)
        || is_link($path)
        || (file_exists($path) && !is_file($path))) {
        return false;
    }

    $directory = dirname($path);
    return $directory !== '' && is_dir($directory) && !is_link($directory);
}

/** Prepare a log parent directory without crossing unsafe path segments. */
function pmssLogWriteDirectoryPrepare(
    string $directory,
    int $mode = 0755,
    ?string &$error = null,
    bool $allowExistingLeafFile = false
): bool {
    $error = null;
    if ($directory === '' || ($directory !== '.' && !pmssPathSegmentsAreSafe($directory, true, true, !$allowExistingLeafFile, true))) {
        $error = 'unsafe';
        return false;
    }
    if ($directory !== '.' && !(is_dir($directory) || @mkdir($directory, $mode, true) || is_dir($directory))) {
        $error = 'create';
        return false;
    }

    return true;
}

/** Append one payload to a JSON Lines file. */
function pmssJsonLineAppend(string $path, array $payload): bool
{
    return pmssLogWritePathIsSafe($path)
        && is_string($encoded = pmssJsonEncodeSafe($payload, JSON_UNESCAPED_SLASHES))
        // Include the delimiter: a partial record is not a successful append.
        && @file_put_contents($path, $encoded.PHP_EOL, FILE_APPEND | LOCK_EX) === strlen($encoded.PHP_EOL);
}

/** Validate a JSON Lines read target before streaming structured log data. */
function pmssJsonLineReadPathIsSafe(string $path): bool
{
    return pmssLogWritePathIsSafe($path) && is_file($path) && !is_link($path);
}

/** Stream decodable JSON Lines entries to a caller-owned handler. */
function pmssJsonLineFileEach(string $path, callable $handler): bool
{
    if (!pmssJsonLineReadPathIsSafe($path)) {
        return false;
    }

    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return false;
    }
    try {
        while (($line = fgets($handle)) !== false) {
            if (($decoded = pmssJsonDecodeAssoc($line)) !== null) {
                $handler($decoded);
            }
        }
        // A failed read before EOF must not report a complete scan.
        return feof($handle);
    } finally {
        // Release the reader even when a handler throws; preserve its exception.
        fclose($handle);
    }
}

/** Read decodable JSON Lines entries from a file. */
function pmssJsonLineFileRead(string $path): array { $entries = []; pmssJsonLineFileEach($path, static function (array $entry) use (&$entries): void { $entries[] = $entry; }); return $entries; }
/** Return the last decodable JSON Lines entry from a file. */
function pmssJsonLineFileLast(string $path): ?array { $last = null; pmssJsonLineFileEach($path, static function (array $entry) use (&$last): void { $last = $entry; }); return $last; }

/** Emit one JSON payload to stdout while keeping encode failures on stderr. */
function pmssJsonEmitPayload(array $payload, string $errorMessage, int $flags = 0): int
{
    if (!is_string($encoded = pmssJsonEncodeSafe($payload, $flags))) {
        fwrite(STDERR, rtrim($errorMessage, "\n").PHP_EOL);
        return 1;
    }

    echo $encoded.PHP_EOL;
    return 0;
}

/** Append one timestamped line to a log file. */
function pmssLogAppendTimestampedLine(string $path, string $message, string $timestampFormat = '[Y-m-d H:i:s] ', string $prefix = '', ?int $mode = null): bool
{
    if (!pmssLogWritePathIsSafe($path)) {
        return false;
    }
    $line = date($timestampFormat).$prefix.$message.PHP_EOL;
    // Keep incomplete writes on the existing failure/fallback path.
    $written = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === strlen($line);
    $written && $mode !== null && @chmod($path, $mode);
    return $written;
}

/** Mirror one message into PMSS log files and the active console stream. */
function pmssLogWriteMessage(string $primary, string $fallback, string $message, bool $writeToStderr = false): void
{
    pmssLogAppendTimestampedLine($primary, $message) || pmssLogAppendTimestampedLine($fallback, $message);
    $writeToStderr ? fwrite(STDERR, $message.PHP_EOL) : print($message.PHP_EOL);
}
