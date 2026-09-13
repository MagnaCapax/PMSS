<?php
/** MOTD health-log projection; retains historical RAID/NVMe and latest SMART warnings. */

require_once __DIR__.'/../runtime.php';

/** Read storage warning text without changing the underlying health records. */
function pmssMotdStorageWarnings(): string
{
    $path = pmssResolvePathFromEnv('PMSS_HEALTH_LOG_PATH', '/var/log/pmss/storage-health.jsonl');
    if (!is_file($path)) return '';
    $raidWarnLine = ''; $raidPerfLine = ''; $nvmeCrit = []; $lastSmart = [];
    pmssJsonLineFileEach($path, static function (array $j) use (&$raidWarnLine, &$raidPerfLine, &$nvmeCrit, &$lastSmart): void {
        $k = $j['kind'] ?? '';
        if ($k==='smart') { $lastSmart[$j['device'] ?? '']=$j; }
        elseif ($k==='raid') {
            if (($j['severity'] ?? 'ok')!=='ok') {
                $flags = implode(',', (array) ($j['flags'] ?? []));
                $raidWarnLine = 'RAID '.($j['array'] ?? 'md').': '.($flags !== '' ? $flags : ($j['state'] ?? 'warn'));
            }
            if (in_array('rebuild_in_progress', (array)($j['flags'] ?? []), true)) {
                $raidPerfLine = 'Performance limited: RAID '.($j['array'] ?? 'md').' resync in progress';
            }
        }
        elseif ($k==='nvme') { if ((int)($j['metrics']['critical_warnings'] ?? 0) > 0) $nvmeCrit[] = $j['device'] ?? 'nvme'; }
    });
    $lines = pmssNonEmptyStrings([$raidWarnLine, $raidPerfLine, empty($nvmeCrit) ? '' : 'NVMe critical warning: '.implode(', ', array_unique($nvmeCrit))]);
    foreach ($lastSmart as $dev=>$s) {
        if (in_array('udma_crc_increase',(array)($s['flags']??[]),true)) $lines[] = 'SATA UDMA CRC increased: '.$dev;
    }
    return implode(' | ', $lines);
}
