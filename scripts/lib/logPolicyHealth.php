<?php
/**
 * Read-only observation of installed log protections and snapshot age.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/update/services/logging/journald.php';
require_once __DIR__.'/update/services/logging/rsyslog.php';

/**
 * Collect policy evidence; injectable reads, capacity and clock keep fixtures hermetic.
 *
 * Missing or ambiguous evidence warns; this never applies policy or starts services.
 * @return array<string,mixed>
 */
function pmssLogPolicyHealthCollect(?callable $read = null, ?int $rootBytes = null, ?int $now = null): array
{
    $read = $read ?: 'pmssReadRegularFileContents';
    $now = $now ?? time();
    $cfg = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $flags = [];
    $weeks = $read($cfg.'/logPolicyMaxAgeWeeks');
    if ($weeks === null) {
        $weeks = '4'; // Optional per-host interval, not a rollout switch.
    }
    if (!is_string($weeks) || strpos($weeks, "\0") !== false
        || preg_match('/^[1-9][0-9]{0,2}$/D', trim($weeks)) !== 1 || (int) $weeks > 520) {
        $flags[] = 'age_limit_invalid';
        $weeks = '4';
    }
    $weeks = (int) $weeks;

    $version = $read($cfg.'/version');
    $completed = $read($cfg.'/version.completed');
    $age = null;
    // Strict round-trip rejects rolled dates, trailing data and unknown content age.
    if (is_string($version) && strpos($version, "\0") === false
        && preg_match('/^[^\s]+@([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2})$/D', trim($version), $match) === 1) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $match[1]);
        if ($date !== false && $date->format('Y-m-d H:i') === $match[1] && $date->getTimestamp() <= $now) {
            $age = $now - $date->getTimestamp();
        }
    }
    if ($age === null) {
        $flags[] = 'snapshot_age_unknown';
    } elseif ($age > $weeks * 7 * 86400) {
        $flags[] = 'snapshot_stale';
    }
    if (!is_string($version) || trim($version) === '' || !is_string($completed)
        || strpos($completed, "\0") !== false || trim($completed) !== trim($version)) {
        $flags[] = 'update_incomplete';
    }

    if ($rootBytes === null) {
        $bytes = @disk_total_space('/');
        $rootBytes = pmssEnvReadDigits('PMSS_ROOT_FS_BYTES')
            ?? (is_numeric($bytes) && $bytes > 0 && $bytes < PHP_INT_MAX ? (int) $bytes : 0);
    }
    $replacements = $rootBytes > 0 ? pmssJournaldTemplateReplacements(pmssJournaldLimitsForRootBytes($rootBytes)) : [];

    $policies = [];
    foreach ([
        'logrotate' => [$cfg.'/template.logrotate.rsyslog', '/etc/logrotate.d/rsyslog'],
        'journald' => [$cfg.'/template.journald.conf.d-pmss-limits.conf', '/etc/systemd/journald.conf.d/pmss-limits.conf'],
    ] as $name => $paths) {
        $template = $read($paths[0]);
        $applied = $read($paths[1]);
        $expected = is_string($template) ? strtr($template, $name === 'journald' ? $replacements : []) : '';
        // Exact managed-file comparison deliberately flags even cosmetic divergence.
        $policies[$name] = !is_string($template) || trim($expected) === '' || strpos($expected, '%%') !== false
            || strpos($expected, "\0") !== false || !is_string($applied) || strpos($applied, "\0") !== false
            || ($name === 'journald' && $rootBytes <= 0)
            ? 'unknown' : ($applied === $expected ? 'ok' : 'drift');
        if ($policies[$name] !== 'ok') {
            $flags[] = $name.'_'.$policies[$name];
        }
    }

    $rsyslog = $read('/etc/rsyslog.conf');
    $declarations = [];
    if (is_string($rsyslog)) {
        foreach (pmssConfigTextLines($rsyslog) as $line) {
            if (strpos($line, 'imklog') !== false) {
                $declarations[] = $line;
            }
        }
    }
    // Custom/multiple declarations are uncertain, including a commented-out cap.
    $policies['rsyslog'] = !is_string($rsyslog) || strpos($rsyslog, "\0") !== false ? 'unknown'
        : ($declarations === [PMSS_RSYSLOG_IMKLOG_LIMITED] ? 'ok' : 'drift');
    if ($policies['rsyslog'] !== 'ok') {
        $flags[] = 'rsyslog_'.$policies['rsyslog'];
    }

    return [
        'timestamp' => date('c', $now), 'event' => 'log_policy_health',
        'level' => $flags ? 'warning' : 'info', 'status' => $flags ? 'warn' : 'ok',
        'snapshot_age_seconds' => $age, 'max_age_weeks' => $weeks,
        'policies' => $policies, 'flags' => $flags,
    ];
}
