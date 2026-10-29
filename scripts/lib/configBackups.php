<?php
/**
 * PMSS config backup helpers for critical service configs.
 * Best-effort only: backup/prune must never be fatal to the caller.
 * Backups live under /var/backups/pmss/config/<service>/ and names include
 * timestamp + source path key + optional PMSS version/correlation id.
 * @license GPL-3.0-only
 */

require_once __DIR__.'/configBackups/context.php';
require_once __DIR__.'/configBackups/retention.php';

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
    $timestamp = preg_match('/^[0-9]{14}$/', (string) ($options['timestamp'] ?? '')) === 1
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
    if ((bool) ($options['logSuccess'] ?? function_exists('logMessage'))) {
        $context['log']('Backup written: '.$backupPath);
    }
    pmssPruneCriticalConfigBackups($context['service'], $context['sourcePath'], $options);
    return $backupPath;
}
