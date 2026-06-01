<?php
/**
 * Shared runtime helpers for PMSS automation scripts.
 *
 * Provides consistent logging and command execution utilities so that
 * provisioning scripts can emit useful diagnostics without aborting on
 * recoverable errors.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/log.php';
const PMSS_LOG_DIR_DEFAULT = '/var/log/pmss';
const PMSS_RUNTIME_DIR_DEFAULT = '/var/run/pmss';
const PMSS_STATE_DIR_DEFAULT = '/var/lib/pmss';
const PMSS_RUNTIME_FALLBACK_LOG = PMSS_LOG_DIR_DEFAULT.'/runtime.log';
const PMSS_COMMAND_TIMEOUT_DEFAULT = 1200;
const PMSS_COMMAND_TIMEOUT_APT_DEFAULT = 1200;
const PMSS_COMMAND_TIMEOUT_KILL_AFTER_DEFAULT = 5;
const PMSS_TIMEOUT_FIRE_LOG_DEFAULT = '/var/log/pmss-timeout-fires.jsonl';
const PMSS_BLOCK_DATA_DEVICE_NAME_PATTERN = '/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/';

// Build the standard month/week/day/hour/15min thresholds used by PMSS stats processors.
function pmssStatsCompareTimesBuild(?int $now = null): array { $now = $now ?? time(); return ['month' => $now - (30 * 24 * 60 * 60), 'week' => $now - (7 * 24 * 60 * 60), 'day' => $now - (24 * 60 * 60), 'hour' => $now - (60 * 60), '15min' => $now - (15 * 60)]; }
    // Accept only bare binary names before crossing the shell boundary.
    function pmssCommandBinaryNameIsSafe(string $binary): bool
    {
        return preg_match('/^[A-Za-z0-9._+-]+$/', $binary) === 1;
    }
    // Match base data block devices while excluding partitions and virtual helpers.
    function pmssBlockDeviceNameIsDataDevice(string $device): bool
    {
        return preg_match(PMSS_BLOCK_DATA_DEVICE_NAME_PATTERN, $device) === 1;
    }
    // Resolve an executable path for a safe bare binary name.
    function pmssCommandPath(string $binary): string
    {
        $binary = trim($binary);
        if ($binary === '' || !pmssCommandBinaryNameIsSafe($binary)) {
            return '';
        }

        $resolved = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');
        if (!is_string($resolved)) {
            return '';
        }

        $path = trim($resolved);
        return $path !== '' && strpos($path, '/') === 0 && is_executable($path) ? $path : '';
    }
    // Quote argv fragments consistently before composing shell command strings.
    function pmssCommandArgvShellQuote(array $argv): string { return implode(' ', array_map(static function ($arg): string { return escapeshellarg((string) $arg); }, $argv)); }
    // Return a 10-sample direct-I/O ioping average normalized to milliseconds.
    function pmssIopingAverageMs(?string $target): ?float
    {
        $bin = pmssCommandPath('ioping');
        if ($bin === '') return null;
        $out = trim((string) shell_exec(escapeshellcmd($bin).' -c 10 -i 0.1 -D '.escapeshellarg((string) $target).' 2>&1 | tail -n1'));
        if (!preg_match('/min\/avg\/max\/mdev\s*=\s*[^\/]+\/\s*([0-9.]+)\s*(us|ms|s)\s*\//i', $out, $m)) return null;
        $value = (float) $m[1];
        return strtolower($m[2]) === 'us' ? $value / 1000.0 : (strtolower($m[2]) === 's' ? $value * 1000.0 : $value);
    }

    // Normalize environment values so flag parsing stays consistent.
    function pmssEnvValueNormalized($value): string { return strtolower(trim((string) $value)); }
    // Compare a normalized scalar value against a caller-owned token set.
    function pmssValueMatchesNormalized($value, array $tokens): bool { return in_array(pmssEnvValueNormalized($value), $tokens, true); }
    // Treat empty and explicit disable values as falsey toggles.
    function pmssEnvValueIsFalsey($value): bool { return pmssValueMatchesNormalized($value, ['', '0', 'false', 'no']); }
    // Treat explicit enable values as truthy toggles.
    function pmssEnvValueIsTruthy($value): bool { return pmssValueMatchesNormalized($value, ['1', 'true', 'yes', 'on']); }
    function pmssEnvFlagEnabled(string $envKey): bool { return getenv($envKey) === '1'; }
    function pmssTestModeEnabled(): bool { return (defined('PMSS_TEST_MODE') && pmssEnvValueIsTruthy(constant('PMSS_TEST_MODE'))) || pmssEnvValueIsTruthy(getenv('PMSS_TEST_MODE')); }
if (!function_exists('pmssFormatBytes')) {
    // Format byte counts with binary IEC units for compact human output.
    function pmssFormatBytes(float $bytes, int $precision = 1, int $minimumUnitIndex = 0): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $index = 0;
        $minimumUnitIndex = max(0, min($minimumUnitIndex, count($units) - 1));
        while ($index < $minimumUnitIndex && $index < count($units) - 1) {
            $bytes /= 1024.0;
            $index++;
        }
        while ($bytes >= 1024.0 && $index < count($units) - 1) {
            $bytes /= 1024.0;
            $index++;
        }

        return number_format($bytes, $index === 0 ? 0 : $precision, '.', '').' '.$units[$index];
    }
}
    // Parse compact byte strings shared by stats, resource plans, and CLI guards.
    function pmssParseSizeToBytes(string $value, bool $wholeNumberOnly = false, bool $allowBareBinarySuffix = false): ?float
    {
        $value = trim($value);
        $numberPattern = $wholeNumberOnly ? '[0-9]+' : '[0-9]+(?:\.[0-9]+)?';
        $suffixPattern = $allowBareBinarySuffix ? '(?:i?B?)?' : '(?:i?B)?';
        if ($value === '' || preg_match('/^('.$numberPattern.')\s*([KMGTPE]?)'.$suffixPattern.'$/i', $value, $matches) !== 1) return null;
        $powers = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5, 'E' => 6];
        return (float) $matches[1] * pow(1024, $powers[strtoupper($matches[2])]);
    }
    // Preserve the legacy MiB parser contract used by lighttpd resource planning.
    function pmssParseSizeToMiB($value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '' || $raw === 'infinity' || $raw === '0') return null;
        if (preg_match('/^[0-9.]+\s*[KMG]?B?$/i', $raw) !== 1) return is_numeric($raw) ? (int) round(((float) $raw) / 1048576) : null;
        $bytes = pmssParseSizeToBytes($raw);
        return $bytes !== null ? (int) round($bytes / 1048576) : null;
    }
    // Trim a config line and drop blank/commented entries.
    function pmssConfigLineTrimmed(string $line, array $commentPrefixes = ['#']): string
    {
        $trimmed = trim($line);
        foreach ($commentPrefixes as $prefix) {
            if ($trimmed !== '' && $prefix !== '' && strpos($trimmed, $prefix) === 0) return '';
        }
        return $trimmed;
    }
    // Split an active config line into whitespace-separated columns.
    function pmssConfigLineColumns(string $line, int $minColumns = 0, array $commentPrefixes = ['#']): array
    {
        $trimmed = pmssConfigLineTrimmed($line, $commentPrefixes);
        if ($trimmed === '') return [];
        $columns = preg_split('/\s+/', $trimmed);
        return is_array($columns) && count($columns) >= $minColumns ? $columns : [];
    }
    // Build updated comma-separated config options after required additions/removals.
    function pmssConfigOptionsUpdatePlan(string $optionList, array $requiredOptions = [], array $removeOptions = [], bool $dropDefaultsOnly = false): array
    {
        $options = array_values(array_filter(explode(',', $optionList), 'strlen'));
        if ($dropDefaultsOnly && $options === ['defaults']) {
            $options = [];
        }
        $removed = [];
        foreach ($removeOptions as $removeOption) {
            $index = array_search($removeOption, $options, true);
            if ($index === false) {
                continue;
            }
            unset($options[$index]);
            $removed[] = $removeOption;
        }
        $options = array_values($options);
        $added = array_values(array_diff($requiredOptions, $options));
        return ['options' => array_merge($options, $added), 'added' => $added, 'removed' => $removed];
    }

    // Resolve the PMSS log directory, allowing hermetic test overrides.
    function pmssLogDir(): string
    {
        return pmssResolvePathFromEnv('PMSS_LOG_DIR', PMSS_LOG_DIR_DEFAULT);
    }

// Share structured `logMessage()` with update helpers via the standalone
// logging bootstrap. `require_once` keeps the import idempotent. Only runtime-
// first callers should disable legacy `logmsg()` forwarding afterwards; update
// bootstraps set that state earlier and must keep it intact.
$pmssLogmsgUsesLogMessageInitialized = array_key_exists('PMSS_LOGMSG_USES_LOGMESSAGE', $GLOBALS);
require_once __DIR__.'/update/logging.php';
if (!$pmssLogmsgUsesLogMessageInitialized) {
    $GLOBALS['PMSS_LOGMSG_USES_LOGMESSAGE'] = false;
}

    function pmssDirEnsureExists(string $path, int $mode = 0755): bool { return is_dir($path) || @mkdir($path, $mode, true) || is_dir($path); }
    // Validate PMSS temp prefixes before they reach tempnam() or cleanup guards.
    function pmssPrivateTempPrefixIsSafe(string $prefix): bool { return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $prefix) === 1; }
    // Create one PMSS-owned temporary file under the process temp root.
    function pmssCreatePrivateTempFile(string $prefix): ?string { return pmssPrivateTempPrefixIsSafe($prefix) && is_string($path = @tempnam(sys_get_temp_dir(), $prefix)) ? $path : null; }
    // Create a private temporary directory under the process temp root.
    function pmssCreatePrivateTempDir(string $prefix): ?string
    {
        if (!pmssPrivateTempPrefixIsSafe($prefix)) { logMessage('[WARN] Refusing private temporary directory with unsafe prefix'); return null; }
        $path = pmssCreatePrivateTempFile($prefix);
        if ($path === null || !@unlink($path) || !@mkdir($path, 0700)) return null;
        return $path;
    }
    // Resolve a PMSS-owned temporary directory before destructive cleanup.
    function pmssPrivateTempDirRealpath(string $path, string $prefix, ?callable $logger = null): ?string
    {
        $log = $logger ?: 'logMessage'; $base = realpath(sys_get_temp_dir()); $real = $path !== '' && !is_link($path) ? realpath($path) : false;
        if (!pmssPrivateTempPrefixIsSafe($prefix)) { $log('[WARN] Refusing temporary directory cleanup for unsafe prefix'); return null; }
        if ($base === false || $base === DIRECTORY_SEPARATOR || $real === false || !is_dir($real)) { $log('[WARN] Refusing temporary directory cleanup for unresolved path: '.$path); return null; }
        $basePrefix = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (strpos($real, $basePrefix) !== 0 || strpos(basename($real), $prefix) !== 0) { $log('[WARN] Refusing temporary directory cleanup outside PMSS temp scope: '.$real); return null; }
        return $real;
    }
    // Read a regular non-symlink file and return its raw contents.
    function pmssReadRegularFileContents(string $path): ?string
    {
        return (!is_file($path) || is_link($path) || !is_string($contents = @file_get_contents($path))) ? null : $contents;
    }
    // Read a regular non-symlink file and return its trimmed contents.
    function pmssReadRegularFileTrimmed(string $path): ?string
    {
        return (($contents = pmssReadRegularFileContents($path)) === null) ? null : trim($contents);
    }
    // Parse Linux meminfo into integer KiB fields for shared resource callers.
    function pmssProcMeminfoFieldsRead(string $path = '/proc/meminfo'): array
    {
        $fields = [];
        foreach (explode("\n", pmssReadRegularFileContents($path) ?? '') as $line) if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches) === 1) $fields[$matches[1]] = (int) $matches[2];
        return $fields;
    }
function pmssProcMeminfoTotalMiBRead(string $path = '/proc/meminfo'): int { $fields = pmssProcMeminfoFieldsRead($path); return isset($fields['MemTotal']) ? (int) round($fields['MemTotal'] / 1024) : 0; }
    // Read a serialized array payload without allowing object wakeups.
    function pmssReadSerializedArrayFile(string $path): ?array
    {
        $raw = pmssReadRegularFileContents($path);
        if ($raw === null || $raw === '') {
            return null;
        }

        $data = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($data) ? $data : null;
    }
    // Read an optional serialized array file; malformed payloads fail closed.
    function pmssReadOptionalSerializedArrayFile(string $path, string $label = 'serialized array file'): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $payload = pmssReadSerializedArrayFile($path);
        if ($payload === null) {
            throw new RuntimeException('Invalid '.$label.': '.$path);
        }

        return $payload;
    }
    // Read one required regular file without following symlinks.
    function pmssReadRequiredRegularFile(string $path, string $label = 'required file'): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Missing '.$label.': '.$path);
        }

        $contents = pmssReadRegularFileContents($path);
        if ($contents === null) {
            throw new RuntimeException('Unable to read '.$label.': '.$path);
        }

        return $contents;
    }
    // Read a regular non-symlink file that must contain digits only.
    function pmssReadRegularFileDigits(string $path): ?string
    {
        return (($raw = pmssReadRegularFileTrimmed($path)) !== null && $raw !== '' && ctype_digit($raw)) ? $raw : null;
    }
    // Validate a network service port while preserving caller-specific ranges.
    function pmssNetworkPortInRange(int $port, int $min = 1, int $max = 65535): bool
    {
        return $min >= 1 && $max <= 65535 && $min <= $max && $port >= $min && $port <= $max;
    }
    // Parse digit-only port payloads from CLI/config files without accepting junk suffixes.
    function pmssNetworkPortParseDigits($value, int $min = 1, int $max = 65535): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $port = (int) $raw;
        return pmssNetworkPortInRange($port, $min, $max) ? $port : null;
    }
    // Read a regular non-symlink file as a bounded network service port.
    function pmssReadRegularFileNetworkPort(string $path, int $min = 1, int $max = 65535): ?int
    {
        $raw = pmssReadRegularFileDigits($path);
        return $raw === null ? null : pmssNetworkPortParseDigits($raw, $min, $max);
    }
    // Resolve a single colon-delimited account row without repeating file scans.
    function pmssColonRecordFieldsLookup(string $path, string $recordName, int $minFields = 2, bool $skipEmptyLines = true): ?array
    {
        if (
            $recordName === ''
            || $minFields < 1
            || strpos($recordName, ':') !== false
            || preg_match('/[\r\n\0\/]/', $recordName) === 1
            || !is_file($path)
            || is_link($path)
        ) {
            return null;
        }

        $flags = FILE_IGNORE_NEW_LINES;
        if ($skipEmptyLines) {
            $flags |= FILE_SKIP_EMPTY_LINES;
        }

        $lines = @file($path, $flags);
        if (!is_array($lines)) {
            return null;
        }

        $prefix = $recordName.':';
        foreach ($lines as $line) {
            if (!is_string($line) || strpos($line, $prefix) !== 0) {
                continue;
            }

            $fields = explode(':', $line);
            return count($fields) >= $minFields ? $fields : null;
        }

        return null;
    }
    // Read a regular non-symlink file that must contain digits to become an int.
    function pmssReadRegularFileInt(string $path, int $default = 0): int
    {
        $raw = pmssReadRegularFileDigits($path);
        return $raw === null ? $default : (int) $raw;
    }
    function pmssHostnameRead(string $default = '', string $path = '/etc/hostname'): string { return is_string($hostname = @file_get_contents($path)) ? trim($hostname) : $default; }
    // Validate a hostname, optionally accepting IPv4 literals for direct node targeting.
    function pmssHostnameIsValid(string $hostname, bool $allowIpv4 = true): bool
    {
        if ($hostname === '' || preg_match('/\s/', $hostname) || strlen($hostname) > 253) return false;
        if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return $allowIpv4;
        if (
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $hostname)
            || strpos($hostname, '..') !== false
            || $hostname[0] === '.'
            || substr($hostname, -1) === '.'
        ) return false;
        foreach (explode('.', $hostname) as $label) {
            if ($label === '' || strlen($label) > 63 || $label[0] === '-' || substr($label, -1) === '-') return false;
        }
        return true;
    }

    function pmssLockFileAcquire(string $path, bool $nonBlocking = false, string $mode = 'c', bool $createParentDir = false, bool $closeOnBusy = true, ?bool &$busy = null)
    {
        $busy = false;
        if ($createParentDir && !pmssDirEnsureExists(dirname($path), 0755)) return false;
        if (($handle = @fopen($path, $mode)) === false) return false;
        if (!@flock($handle, LOCK_EX | ($nonBlocking ? LOCK_NB : 0))) {
            $busy = true;
            if ($closeOnBusy) { @fclose($handle); return false; }
        }
        return $handle;
    }
    function pmssLockHandleWritePid($handle): void { @ftruncate($handle, 0); @rewind($handle); @fwrite($handle, (string) getmypid()); @fflush($handle); }
    /** Validate runtime lock filenames before joining them to a writable directory. */
    function pmssRuntimeLockBasename(string $basename): string
    {
        $basename = ltrim($basename, '/');
        if (
            $basename === ''
            || $basename === '.'
            || $basename === '..'
            || strpos($basename, '/') !== false
            || preg_match('/[\r\n\0]/', $basename) === 1
        ) {
            throw new RuntimeException('Unsafe runtime lock basename');
        }

        return $basename;
    }

    function pmssRuntimeLockPath(string $basename): string { return (is_dir('/run/lock') ? '/run/lock' : '/tmp').'/'.pmssRuntimeLockBasename($basename); }
    function pmssLockHandleRelease($handle, bool $unlock = true): void { $unlock && @flock($handle, LOCK_UN); @fclose($handle); }
    // Resolve the PMSS runtime directory, allowing hermetic test overrides.
    function pmssRuntimeDir(): string
    {
        return pmssResolvePathFromEnv('PMSS_RUNTIME_DIR', PMSS_RUNTIME_DIR_DEFAULT);
    }

    // Resolve the PMSS state directory, allowing hermetic test overrides.
    function pmssStateDir(): string
    {
        return pmssResolvePathFromEnv('PMSS_STATE_DIR', PMSS_STATE_DIR_DEFAULT);
    }

    /** Detect whether a stream resource is attached to a terminal. */
    function pmssStreamIsTty($stream, bool $defaultWhenUnavailable = false): bool
    {
        if (!is_resource($stream)) {
            return $defaultWhenUnavailable;
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream);
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty($stream);
        }
        return $defaultWhenUnavailable;
    }

    /** Detect whether all standard streams are attached to terminals. */
    function pmssStandardStreamsAreTty(): bool
    {
        return pmssStreamIsTty(STDIN) && pmssStreamIsTty(STDOUT) && pmssStreamIsTty(STDERR);
    }

    function pmssSystemdRuntimeAvailable(string $runtimeDir = '/run/systemd/system'): bool { return is_dir($runtimeDir); }
    function pmssSystemdUnitNameIsSafe(string $unit): bool { $unit = trim($unit); return $unit !== '' && strpos($unit, '-') !== 0 && preg_match('/^[A-Za-z0-9:_.@\\-]+$/', $unit) === 1; }
    function pmssSystemdUnitActionNameIsSafe(string $action): bool { return isset(['disable' => true, 'enable' => true, 'mask' => true, 'reload' => true, 'restart' => true, 'start' => true, 'stop' => true, 'try-reload-or-restart' => true, 'try-restart' => true, 'unmask' => true][trim($action)]); }
    function pmssSystemdUnitStateActionNameIsSafe(string $action): bool { return $action === 'is-active' || $action === 'is-enabled'; }
    function pmssSystemdUnitState(string $action, string $unit): ?string { if (!pmssSystemdUnitStateActionNameIsSafe($action) || !pmssSystemdRuntimeAvailable() || !pmssSystemdUnitNameIsSafe($unit)) return null; return trim((string) @shell_exec('systemctl '.$action.' '.escapeshellarg($unit).' 2>/dev/null')); }
    function pmssSystemdUnitQuietStatus(string $action, string $unit): ?bool { if (!pmssSystemdUnitStateActionNameIsSafe($action) || !pmssSystemdRuntimeAvailable() || !pmssSystemdUnitNameIsSafe($unit)) return null; exec('systemctl '.$action.' --quiet '.escapeshellarg($unit), $_, $rc); return $rc === 0; }
    function pmssSystemdUnitIsActive(string $unit): ?bool { return pmssSystemdUnitQuietStatus('is-active', $unit); }
    function pmssSystemdUnitIsEnabled(string $unit): ?bool { return pmssSystemdUnitQuietStatus('is-enabled', $unit); }
require_once __DIR__.'/runtime/commands.php';

    /**
     * Abort with a clear error when the current user is not root.
     */
    function requireRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            pmssError("This script must be run as root.");
            exit(1);
        }
    }

if (!function_exists('pmssRequireCli')) {
    /**
     * Enforce CLI execution for script entrypoints and reusable CLI flows.
     */
    function pmssRequireCli(string $message = 'This script must be run from the command line.', ?int $failureCode = 1): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }

        fwrite(STDERR, rtrim($message, "\r\n").PHP_EOL);
        if ($failureCode !== null) {
            exit($failureCode);
        }

        return false;
    }
}

    /**
     * Apply the standard CLI/bootstrap guard used by thin script wrappers.
     */
    function pmssPrepareCliEntrypoint(bool $rootRequired = false, array $argvAppend = []): void
    {
        pmssRequireCli();
        if ($rootRequired) {
            requireRoot();
        }

        if (empty($argvAppend)) {
            return;
        }

        if (!isset($GLOBALS['argv']) || !is_array($GLOBALS['argv'])) {
            $GLOBALS['argv'] = $_SERVER['argv'] ?? [];
        }
        if (!isset($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
            $_SERVER['argv'] = $GLOBALS['argv'];
        }

        foreach ($argvAppend as $arg) {
            $arg = (string) $arg;
            $GLOBALS['argv'][] = $arg;
            $_SERVER['argv'][] = $arg;
        }
    }

    function pmssRequireCliEntrypointScript(string $baseDir, string $relativePath, bool $rootRequired = false, array $argvAppend = []): void
    {
        pmssPrepareCliEntrypoint($rootRequired, $argvAppend);
        require_once rtrim($baseDir, '/').'/'.ltrim($relativePath, '/');
    }

    function pmssRunCliEntrypoint(string $scriptPath, callable $main): void
    {
        if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === $scriptPath) exit((int) $main());
    }

    /** Run a direct CLI entrypoint and pass through the current argv vector. */
    function pmssRunCliEntrypointWithArgv(string $scriptPath, callable $main): void
    {
        pmssRunCliEntrypoint($scriptPath, static function () use ($main): int {
            $argv = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? []);
            return (int) $main(is_array($argv) ? $argv : []);
        });
    }
    // Run a processor object exposing runCli($argv, $scriptPath) as a CLI entrypoint.
    function pmssRunCliProcessorEntrypoint(string $scriptPath, object $processor): void { pmssRunCliEntrypointWithArgv($scriptPath, static function (array $argv) use ($processor, $scriptPath): int { return (int) $processor->runCli($argv, (string) ($argv[0] ?? $scriptPath)); }); }

require_once __DIR__.'/runtime/snapshot.php';

    /**
     * Write an error message to STDERR and the log.
     */
    function pmssError(string $message): void
    {
        // Use ANSI red for visibility if interactive, otherwise plain text
        $isTty = pmssStreamIsTty(STDERR);
        $prefix = $isTty ? "\033[31m[ERROR]\033[0m " : "[ERROR] ";

        fwrite(STDERR, $prefix . $message . PHP_EOL);
        logMessage('[ERROR] ' . $message); // Persist to logfile
    }
