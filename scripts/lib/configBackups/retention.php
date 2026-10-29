<?php
/** Critical-config backup retention over the sorted filename stream. @license GPL-3.0-only */

require_once __DIR__.'/context.php';

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
    if ($context === null
        || !is_dir($context['serviceDir'])
        || empty($files = glob($context['serviceDir'].'/*__'.$context['key'].'*.bak'))
    ) {
        return;
    }
    $maxCount = (int) ($options['maxCount'] ?? 10);
    $ttlSeconds = (int) ($options['ttlSeconds'] ?? (90 * 86400));
    $nowTs = (int) ($options['nowTs'] ?? time());
    // Sort by filename (timestamp prefix) descending so we keep the newest ones.
    rsort($files, SORT_STRING);
    $cutoff = $ttlSeconds > 0 ? ($nowTs - $ttlSeconds) : null;
    // Age expiry never replenishes the count window, including undated filenames.
    foreach ($files as $index => $file) {
        if ((($cutoff !== null
            && preg_match('/^([0-9]{14})__/', basename($file), $matches)
            && ($dateTime = \DateTime::createFromFormat('YmdHis', $matches[1])) !== false
            && $dateTime->getTimestamp() < $cutoff
        ) || ($maxCount > 0 && $index >= $maxCount)) && !@unlink($file)) {
            $context['log']('[WARN] Unable to prune config backup: '.$file);
        }
    }
}
