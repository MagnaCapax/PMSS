#!/usr/bin/php
<?php
/**
 * Storage health snapshot (SMART/NVMe + mdadm) to JSONL.
 *
 * - SMART (smartctl): -n standby -H -A; parse Reallocated, Pending, UDMA_CRC, Temp
 * - NVMe (nvme smart-log): critical_warnings, temperature, media_errors, err log, percentage_used
 * - RAID (mdadm via /proc/mdstat): degraded/resync states
 *
 * Absolutely NO ZFS here. We like our data intact, accessible, and performant —
 * no ZFS bullshit anywhere in these fleets. If you add ZFS support, expect a
 * stern code review and a rubber chicken.
 */

require_once __DIR__.'/../lib/runtime.php';

$logPath = '/var/log/pmss/storage-health.jsonl';
if (!is_dir(dirname($logPath))) @mkdir(dirname($logPath), 0755, true);

function readLastEntries(string $path): array {
    if (!is_file($path)) return [];
    $fh = fopen($path, 'r'); if (!$fh) return [];
    $last = [];
    while (($line = fgets($fh)) !== false) {
        $j = json_decode($line, true);
        if (!is_array($j)) continue;
        $key = ($j['kind'] ?? '').'::'.($j['device'] ?? ($j['array'] ?? 'global'));
        $last[$key] = $j;
    }
    fclose($fh);
    return $last;
}

function appendJson(string $path, array $entry): void {
    @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
}

function listDisks(): array {
    $out = shell_exec('lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE 2>/dev/null');
    $devs = [];
    if (!$out) return $devs;
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        if ($line === '') continue;
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 3) continue;
        $kname = $parts[0]; $type = $parts[1]; $rota = (int)$parts[2];
        if ($type !== 'disk') continue;
        if (strpos($kname,'loop')===0 || strpos($kname,'ram')===0) continue;
        $sizeStr = $parts[count($parts)-1] ?? '';
        $serial  = $parts[count($parts)-2] ?? '';
        $modelArr = array_slice($parts, 3, max(0,count($parts)-5));
        $model = implode(' ', $modelArr);
        $devs[] = [
            'path' => '/dev/'.$kname,
            'kname'=> $kname,
            'rota' => $rota,
            'model'=> $model,
            'serial'=>$serial,
            'size' => $sizeStr,
        ];
    }
    return $devs;
}

function snapshotSmart(array $disk, array $last): array {
    $dev = $disk['path'];
    $entry = [
        'timestamp'=> date('c'),
        'kind'     => 'smart',
        'device'   => $dev,
        'kname'    => $disk['kname'],
        'model'    => $disk['model'],
        'serial'   => $disk['serial'],
        'rota'     => $disk['rota'],
        'size'     => $disk['size'],
        'ok'       => false,
        'severity' => 'warn',
    ];
    if (!is_readable($dev) || trim((string) shell_exec('command -v smartctl 2>/dev/null')) === '') {
        $entry['error'] = 'smartctl missing or device unreadable';
        return $entry;
    }
    $out = shell_exec('smartctl -n standby,now -H -A '.escapeshellarg($dev).' 2>/dev/null');
    if (!$out) { $entry['error']='smartctl produced no output'; return $entry; }
    if (stripos($out, 'Device is in STANDBY') !== false || stripos($out,'Device is in SLEEP') !== false) {
        $entry['ok'] = true; $entry['severity']='ok'; $entry['flags']=['standby'];
        return $entry;
    }
    $metrics = [ 'health'=>'UNKNOWN', 'reallocated'=>null, 'pending'=>null, 'udma_crc'=>null, 'temp_c'=>null ];
    if (preg_match('/SMART overall-health.*?:\s*(\S+)/i', $out, $m)) {
        $metrics['health'] = strtoupper($m[1]);
    }
    foreach (preg_split('/\r?\n/', $out) as $line) {
        if (preg_match('/\bReallocated_Sector_Ct\b.*?\s(\d+)\s*$/', $line, $m)) $metrics['reallocated'] = (int)$m[1];
        if (preg_match('/\bCurrent_Pending_Sector\b.*?\s(\d+)\s*$/', $line, $m)) $metrics['pending'] = (int)$m[1];
        if (preg_match('/\bUDMA_CRC_Error_Count\b.*?\s(\d+)\s*$/', $line, $m)) $metrics['udma_crc'] = (int)$m[1];
        if (preg_match('/\bTemperature(_Celsius)?\b.*?\s(\d+)\s*$/', $line, $m)) $metrics['temp_c'] = (int)$m[2];
        if (preg_match('/^194\s+Temperature_Celsius.*?\s(\d+)\s*$/', $line, $m)) $metrics['temp_c'] = (int)$m[1];
    }
    $entry['metrics'] = $metrics;
    $flags = [];
    $sev = 'ok';
    if ($metrics['health'] !== 'PASSED') { $sev='fail'; $flags[]='health_not_passed'; }
    if (($metrics['pending'] ?? 0) > 0) { $sev = max($sev,'warn'); $flags[]='pending_sectors'; }
    // Temp thresholds by media class
    $temp = $metrics['temp_c'] ?? null;
    if ($temp !== null) {
        if (($disk['rota'] ?? 1) == 1) { if ($temp >= 50) { $flags[]='hot_hdd'; $sev = max($sev,'warn'); } }
        else { if ($temp >= 70) { $flags[]='hot_ssd'; $sev = max($sev,'warn'); } }
    }
    // Drift detection vs last
    $prev = $last['smart::'.$dev]['metrics'] ?? null;
    if (is_array($prev)) {
        if (isset($metrics['reallocated'],$prev['reallocated']) && $metrics['reallocated'] > $prev['reallocated']) $flags[]='reallocated_increase';
        if (isset($metrics['pending'],$prev['pending']) && $metrics['pending'] > $prev['pending']) { $flags[]='pending_increase'; $sev = max($sev,'warn'); }
        if (isset($metrics['udma_crc'],$prev['udma_crc']) && $metrics['udma_crc'] > $prev['udma_crc']) $flags[]='udma_crc_increase';
    }
    $entry['flags'] = $flags; $entry['severity']=$sev; $entry['ok'] = ($sev==='ok');
    return $entry;
}

function snapshotNvme(array $disk, array $last): ?array {
    $dev = $disk['path'];
    if (strpos($dev,'nvme') === false) return null;
    if (trim((string) shell_exec('command -v nvme 2>/dev/null')) === '') return null;
    $out = shell_exec('nvme smart-log '.escapeshellarg($dev).' 2>/dev/null');
    if (!$out) return null;
    $metrics = [ 'critical_warnings'=>null, 'temperature'=>null, 'media_errors'=>null, 'num_err_log_entries'=>null, 'percentage_used'=>null ];
    if (preg_match('/critical_warning\s*:\s*(\d+)/i', $out, $m)) $metrics['critical_warnings'] = (int)$m[1];
    if (preg_match('/temperature\s*:\s*([0-9]+)\s*([KC])?/i', $out, $m)) {
        $val = (int)$m[1]; $unit = strtoupper($m[2] ?? 'C');
        $metrics['temperature'] = ($unit === 'K') ? ($val - 273) : $val;
    }
    if (preg_match('/media_errors\s*:\s*(\d+)/i', $out, $m)) $metrics['media_errors'] = (int)$m[1];
    if (preg_match('/num_err_log_entries\s*:\s*(\d+)/i', $out, $m)) $metrics['num_err_log_entries'] = (int)$m[1];
    if (preg_match('/percentage_used\s*:\s*(\d+)/i', $out, $m)) $metrics['percentage_used'] = (int)$m[1];
    $entry = [
        'timestamp'=> date('c'),
        'kind'     => 'nvme',
        'device'   => $dev,
        'kname'    => $disk['kname'],
        'model'    => $disk['model'],
        'serial'   => $disk['serial'],
        'rota'     => $disk['rota'],
        'size'     => $disk['size'],
        'metrics'  => $metrics,
    ];
    $flags = []; $sev='ok';
    if (($metrics['critical_warnings'] ?? 0) > 0) { $sev='fail'; $flags[]='nvme_critical_warning'; }
    if (($metrics['temperature'] ?? 0) >= 70) { $sev = max($sev,'warn'); $flags[]='hot_nvme'; }
    if (($metrics['percentage_used'] ?? 0) >= 95) { $sev='warn'; $flags[]='wearout_critical'; }
    elseif (($metrics['percentage_used'] ?? 0) >= 80) { $flags[]='wearout_high'; }
    $prev = $last['nvme::'.$dev]['metrics'] ?? null;
    if (is_array($prev)) {
        if (isset($metrics['media_errors'],$prev['media_errors']) && $metrics['media_errors'] > $prev['media_errors']) { $flags[]='media_errors_increase'; $sev = max($sev,'warn'); }
        if (isset($metrics['num_err_log_entries'],$prev['num_err_log_entries']) && $metrics['num_err_log_entries'] > $prev['num_err_log_entries']) { $flags[]='err_log_increase'; }
    }
    $entry['flags']=$flags; $entry['severity']=$sev; $entry['ok']=($sev==='ok');
    return $entry;
}

function snapshotRaid(): array {
    $entries = [];
    $md = @file_get_contents('/proc/mdstat');
    if ($md !== false) {
        $currentArray = null; $state = '';
        foreach (preg_split('/\r?\n/', $md) as $line) {
            if (preg_match('/^(md\d+)\s*:\s*(\w+)\s+(raid\d)\s+(.*)$/', trim($line), $m)) {
                $currentArray = $m[1]; $state = $m[2]; $level = $m[3]; $detail = $m[4];
                $entry = [ 'timestamp'=>date('c'), 'kind'=>'raid', 'array'=>$currentArray, 'level'=>$level, 'state'=>$state, 'detail'=>$detail, 'ok'=>true, 'severity'=>'ok', 'flags'=>[] ];
                if (preg_match('/\[(\d+)\/(\d+)\]\s*\[([U_]+)\]/', $detail, $mm)) {
                    $n = (int)$mm[1]; $memb=(int)$mm[2]; $map=$mm[3];
                    if (strpos($map,'_') !== false || $n !== $memb) { $entry['severity']='fail'; $entry['ok']=false; $entry['flags'][]='degraded'; }
                }
                $entries[]=$entry;
            } elseif ($currentArray && (strpos($line,'resync')!==false || strpos($line,'recovery')!==false || strpos($line,'reshape')!==false)) {
                $lastIdx = count($entries)-1; if ($lastIdx>=0) {
                    $entries[$lastIdx]['severity'] = max($entries[$lastIdx]['severity'],'warn');
                    $entries[$lastIdx]['flags'][] = 'rebuild_in_progress';
                    $entries[$lastIdx]['resync'] = trim($line);
                }
            }
        }
    }
    return $entries;
}

// --- Run snapshots ---
$last = readLastEntries($logPath);
foreach (listDisks() as $disk) {
    appendJson($logPath, snapshotSmart($disk, $last));
    $n = snapshotNvme($disk, $last); if ($n) appendJson($logPath, $n);
}
foreach (snapshotRaid() as $e) appendJson($logPath, $e);
echo "Storage health snapshot written to {$logPath}\n";

