<?php
/**
 * PMSS config backup helpers for critical service configs.
 * Best-effort only: backup/prune must never be fatal to the caller.
 * Backups live under /var/backups/pmss/config/<service>/ and names include
 * timestamp + source path key + optional PMSS version/correlation id.
 * @license GPL-3.0-only
 */

require_once __DIR__.'/runtime.php';

$GLOBALS['PMSS_CONFIG_BACKUPS_FALLBACK_LOGGER'] = $GLOBALS['PMSS_CONFIG_BACKUPS_FALLBACK_LOGGER']
    ?? function (string $message): void {
        if (defined('STDERR')) {
            @fwrite(STDERR, $message.PHP_EOL);
            return;
        }
        error_log($message);
    };

/**
 * Create a best-effort backup of a file and prune older backups.
 *
 * @param string $service E.g. "sshd", "nginx", "proftpd".
 * @param string $sourcePath Absolute path to the file being mutated.
 * @param array<string,mixed> $options Optional overrides:
 *   - backupRoot: string base directory (default: /var/backups/pmss/config)
 *   - timestamp: string 14-digit YmdHis (default: date('YmdHis'))
 *   - pmssVersion: string|null version string (default: auto-detect when possible)
 *   - correlationId: string|null correlation id (default: getenv('PMSS_CORRELATION_ID'))
 *   - maxCount: int maximum backups to keep for this key (default: 10)
 *   - ttlSeconds: int maximum age to retain (default: 90 days)
 *   - nowTs: int epoch seconds override for prune tests (default: time())
 *   - logger: callable(string):void|null logger override
 *
 * @return string|null The backup path on success, null otherwise.
 */
function pmssBackupCriticalConfig(string $service, string $sourcePath, array $options = array()): ?string
{
    $context = pmssConfigBackupsPrepareContext($service, $sourcePath, $options, true, '[WARN] Refusing config backup with invalid service name');
    if ($context === null) {
        return null;
    }
    $timestamp = isset($options['timestamp']) && preg_match('/^[0-9]{14}$/', (string) $options['timestamp'])
        ? (string) $options['timestamp']
        : date('YmdHis');
    $pmssVersion = array_key_exists('pmssVersion', $options)
        ? (string) $options['pmssVersion']
        : (function_exists('getPmssVersion') ? (string) getPmssVersion() : 'unknown');
    if ($pmssVersion === 'unknown') {
        foreach (array('/etc/seedbox/config/version', '/etc/seedbox/runtime/version') as $path) {
            if (($detectedVersion = trim((string) @file_get_contents($path))) !== '') {
                $pmssVersion = $detectedVersion;
                break;
            }
        }
    }
    $correlationId = array_key_exists('correlationId', $options) ? (string) $options['correlationId'] : (string) (getenv('PMSS_CORRELATION_ID') ?: '');
    if (!pmssDirEnsureExists($context['serviceDir'], 0700)) {
        $context['log']('[WARN] Unable to create config backup directory: '.$context['serviceDir']);
        return null;
    }
    @chmod($context['serviceDir'], 0700);
    $name = $timestamp.'__'.$context['key'];
    if (($versionLabel = pmssConfigBackupsSanitizeLabel($pmssVersion)) !== '' && $versionLabel !== 'unknown') { $name .= '__v='.$versionLabel; }
    if (($cidLabel = pmssConfigBackupsSanitizeLabel($correlationId)) !== '') { $name .= '__cid='.$cidLabel; }
    $backupPath = $context['serviceDir'].'/'.$name.'.bak';
    if (!@copy($context['sourcePath'], $backupPath)) {
        $context['log']('[WARN] Failed to create config backup for '.$context['sourcePath'].' at '.$backupPath);
        return null;
    }
    @chmod($backupPath, 0600);
    if (isset($options['logSuccess']) ? (bool) $options['logSuccess'] : function_exists('logMessage')) {
        $context['log']('Backup written: '.$backupPath);
    }
    pmssPruneCriticalConfigBackups($context['service'], $context['sourcePath'], $options);
    return $backupPath;
}

/**
 * Prune config backups for a given source path (best-effort).
 *
 * @param string $service E.g. "sshd", "nginx", "proftpd".
 * @param string $sourcePath Absolute path to the file being mutated.
 * @param array<string,mixed> $options See pmssBackupCriticalConfig() for supported keys.
 */
function pmssPruneCriticalConfigBackups(string $service, string $sourcePath, array $options = array()): void
{
    $context = pmssConfigBackupsPrepareContext($service, $sourcePath, $options, false, '[WARN] Refusing config backup prune with invalid service name');
    if ($context === null) {
        return;
    }
    if (!is_dir($context['serviceDir']) || empty($files = glob($context['serviceDir'].'/*__'.$context['key'].'*.bak'))) {
        return;
    }
    $maxCount = isset($options['maxCount']) ? (int) $options['maxCount'] : 10;
    $ttlSeconds = isset($options['ttlSeconds']) ? (int) $options['ttlSeconds'] : (90 * 86400);
    $nowTs = isset($options['nowTs']) ? (int) $options['nowTs'] : time();
    // Sort by filename (timestamp prefix) descending so we keep the newest ones.
    rsort($files, SORT_STRING);
    $keptMap = array_fill_keys($maxCount > 0 ? array_slice($files, 0, $maxCount) : $files, true);
    $cutoff = $ttlSeconds > 0 ? ($nowTs - $ttlSeconds) : null;
    foreach ($files as $file) {
        $remove = !isset($keptMap[$file]);
        if (
            $cutoff !== null
            && preg_match('/^([0-9]{14})__/', basename($file), $matches)
            && ($dateTime = \DateTime::createFromFormat('YmdHis', $matches[1])) !== false
            && $dateTime->getTimestamp() < $cutoff
        ) {
            $remove = true;
        }
        if ($remove && !@unlink($file)) {
            $context['log']('[WARN] Unable to prune config backup: '.$file);
        }
    }
}

/** @param array<string,mixed> $options @return array<string,mixed>|null */
function pmssConfigBackupsPrepareContext(string $service, string $sourcePath, array $options, bool $requireReadableSource, string $invalidServiceMessage): ?array
{
    $log = $options['logger'] ?? (function_exists('logMessage')
        ? 'logMessage'
        : $GLOBALS['PMSS_CONFIG_BACKUPS_FALLBACK_LOGGER']);
    $service = pmssConfigBackupsNormalizeService($service);
    $sourcePath = trim($sourcePath);
    if ($service === '' || $sourcePath === '' || getenv('PMSS_DRY_RUN') === '1') {
        if ($service === '') {
            $log($invalidServiceMessage);
        }
        return null;
    }
    if (!pmssConfigBackupsPathIsAbsolute($sourcePath)) {
        $log('[WARN] Refusing config backup for non-absolute source path: '.$sourcePath);
        return null;
    }
    if (strpos($sourcePath, "\0") !== false || is_link($sourcePath)) {
        $log('[WARN] Refusing config backup for unsafe source path: '.$sourcePath);
        return null;
    }
    if ($requireReadableSource && (!is_file($sourcePath) || !is_readable($sourcePath))) {
        return null;
    }
    $backupRoot = rtrim((string) ($options['backupRoot'] ?? '/var/backups/pmss/config'), '/') ?: '/var/backups/pmss/config';
    if (!pmssConfigBackupsPathIsAbsolute($backupRoot)) {
        $log('[WARN] Refusing config backup with non-absolute backup root: '.$backupRoot);
        return null;
    }
    if (
        strpos($backupRoot, "\0") !== false
        || is_link($backupRoot)
        || (file_exists($backupRoot) && !is_dir($backupRoot))
    ) {
        $log('[WARN] Refusing config backup with unsafe backup root: '.$backupRoot);
        return null;
    }
    $serviceDir = $backupRoot.'/'.$service;
    if (is_link($serviceDir) || (file_exists($serviceDir) && !is_dir($serviceDir))) {
        $log('[WARN] Refusing config backup with unsafe service directory: '.$serviceDir);
        return null;
    }
    return array(
        'key' => pmssConfigBackupsPathKey($sourcePath),
        'log' => $log,
        'service' => $service,
        'serviceDir' => $serviceDir,
        'sourcePath' => $sourcePath,
    );
}

function pmssConfigBackupsPathIsAbsolute(string $path): bool
{
    return $path !== '' && strpos($path, '/') === 0;
}

/**
 * Convert a filesystem path into a stable, safe filename key.
 */
function pmssConfigBackupsPathKey(string $path): string
{
    $path = preg_replace('/\\s+/', ' ', trim($path));
    if (preg_match('#/(etc|home|opt|srv|usr|var)(?:/|$)#', $path, $matches, PREG_OFFSET_CAPTURE) === 1) {
        $path = substr($path, $matches[0][1]);
    }
    return ($path = ltrim(str_replace('/', '_', preg_replace('/[^A-Za-z0-9._\\/\\-]/', '_', $path)), '_')) !== '' ? $path : 'unknown_path';
}

/**
 * Normalize a service key used as the backup directory name.
 */
function pmssConfigBackupsNormalizeService(string $service): string
{
    $service = trim($service);
    return preg_match('/^[A-Za-z0-9._-]+$/', $service) === 1 ? $service : '';
}

/**
 * Sanitize a label for embedding in backup file names.
 */
function pmssConfigBackupsSanitizeLabel(string $label, int $maxLen = 80): string
{
    $label = trim(preg_replace('/[^A-Za-z0-9._\\-]+/', '_', preg_replace('/\\s+/', ' ', trim($label))), '_');
    return $label === '' ? '' : substr($label, 0, $maxLen);
}
