<?php
/** MOTD generator (class-based). */

require_once __DIR__.'/../update.php';

class Motd
{
    public static function motdGenerate(): void
    {
        $tplPath = getenv('PMSS_MOTD_TEMPLATE_PATH') ?: '/etc/seedbox/config/template.motd';
        $outPath = getenv('PMSS_MOTD_OUTPUT_PATH') ?: '/etc/motd';
        $tpl      = @file_get_contents($tplPath);
        if ($tpl === false) return;

        [$host,$ip,$cpu,$ram,$storage] = self::sysBasics();
        [$pmssVersion,$runtimeVersion,$updateDate] = self::versionInfo();
        [$uptime,$kernel,$netSpeed] = self::runtimeInfo();
        [$wg,$ovpn] = self::serviceStatuses();
        $storageWarn = self::storageWarnings();

        $repl = [
            '%HOSTNAME%'        => $host,
            '%SERVER_IP%'       => $ip,
            '%SERVER_CPU%'      => $cpu,
            '%SERVER_RAM%'      => $ram,
            '%SERVER_STORAGE%'  => $storage,
            '%PMSS_VERSION%'    => $pmssVersion,
            '%RUN_VERSION%'     => $runtimeVersion,
            '%UPDATE_DATE%'     => $updateDate,
            '%APT_LAST_UPDATE%' => self::aptLastUpdate(),
            '%UPTIME%'          => $uptime,
            '%KERNEL_VERSION%'  => $kernel,
            '%NETWORK_SPEED%'   => $netSpeed,
            '%WIREGUARD_STATUS%'=> $wg,
            '%OPENVPN_STATUS%'  => $ovpn,
        ];
        foreach ($repl as $k => $v) $tpl = str_replace($k, $v, $tpl);
        if ($storageWarn !== '') {
            $tpl .= "\n\e[33mStorage WARN:\e[0m ".$storageWarn."\n";
        }
        file_put_contents($outPath, $tpl);
    }

    private static function sysBasics(): array
    {
        $host = trim((string) @file_get_contents('/etc/hostname'));
        $ip   = gethostbyname($host);
        $cpu  = trim((string) shell_exec("lscpu | grep 'Model name:' | sed 's/Model name:\\s*//'"));
        $ram  = trim((string) shell_exec("free -h | awk '/^Mem:/ { print \$2 }'"));
        $stor = trim((string) shell_exec("df -h /home | awk 'NR==2 {print \$2}'"));
        return [$host,$ip,$cpu,$ram,$stor];
    }

    private static function versionInfo(): array
    {
        $pmssVersion = getPmssVersion();
        $runtimeDir = getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss';
        if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0770, true);
        $verCache = rtrim($runtimeDir,'/').'/version';
        @file_put_contents($verCache, $pmssVersion);
        $runtimeVersion = trim((string) @file_get_contents($verCache));
        $updateDate = is_file('/var/run/pmss/updated') ? trim((string) @file_get_contents('/var/run/pmss/updated')) : 'not set';
        return [$pmssVersion,$runtimeVersion,$updateDate];
    }

    private static function aptLastUpdate(): string
    {
        $f = '/var/lib/apt/periodic/update-success-stamp';
        return is_file($f) ? trim((string) shell_exec("stat -c '%y' ".escapeshellarg($f))) : 'Not available';
    }

    private static function runtimeInfo(): array
    {
        $uptime  = trim((string) shell_exec('uptime -p'));
        $kernel  = trim((string) shell_exec('uname -r'));
        $nsRaw   = shell_exec("ethtool eth0 2>/dev/null | grep 'Speed:'");
        $net     = ($nsRaw && preg_match('/Speed:\s+(\S+)/', $nsRaw, $m)) ? $m[1] : 'N/A';
        return [$uptime,$kernel,$net];
    }

    private static function serviceStatuses(): array
    {
        $color = static function (string $text, string $color): string { return "\e[{$color}m{$text}\e[0m"; };
        $svc = static function (string $service, ?string $configPath, string $name) use ($color): string {
            if ($configPath !== null && !file_exists($configPath)) {
                return $color('not configured','33');
            }
            if (!is_dir('/run/systemd/system')) return $color('unknown','33');
            exec('systemctl is-active --quiet '.escapeshellarg($service), $o, $rc);
            if ($rc === 0) return $color('active','32');
            exec('systemctl is-enabled --quiet '.escapeshellarg($service), $o, $en);
            return $en !== 0 ? $color('disabled','33') : $color('inactive','31');
        };
        return [
            $svc('wg-quick@wg0','/etc/wireguard/wg0.conf','WireGuard'),
            $svc('openvpn@openvpn','/etc/openvpn/openvpn.conf','OpenVPN'),
        ];
    }

    private static function storageWarnings(): string
    {
        $path = getenv('PMSS_HEALTH_LOG_PATH') ?: '/var/log/pmss/storage-health.jsonl';
        if (!is_file($path)) return '';
        $fh = @fopen($path,'r'); if (!$fh) return '';
        $raidWarn = null; $nvmeCrit=[]; $lastSmart=[];
        while (($line=fgets($fh))!==false) {
            $j = json_decode($line,true); if (!is_array($j)) continue;
            $k = $j['kind'] ?? '';
            if ($k==='smart') { $lastSmart[$j['device'] ?? '']=$j; }
            elseif ($k==='raid') { if (($j['severity'] ?? 'ok')!=='ok') $raidWarn=$j; }
            elseif ($k==='nvme') { if ((int)($j['metrics']['critical_warnings'] ?? 0) > 0) $nvmeCrit[] = $j['device'] ?? 'nvme'; }
        }
        fclose($fh);
        $lines=[];
        if ($raidWarn) {
            $arr=$raidWarn['array'] ?? 'md'; $flags=implode(',',(array)($raidWarn['flags']??[]));
            $lines[] = "RAID $arr: ".($flags!==''?$flags:($raidWarn['state']??'warn'));
        }
        if (!empty($nvmeCrit)) $lines[] = 'NVMe critical warning: '.implode(', ', array_unique($nvmeCrit));
        foreach ($lastSmart as $dev=>$s) {
            if (in_array('udma_crc_increase',(array)($s['flags']??[]),true)) $lines[] = 'SATA UDMA CRC increased: '.$dev;
        }
        return implode(' | ', $lines);
    }
}

