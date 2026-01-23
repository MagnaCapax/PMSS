<?php
/** MOTD generator (class-based). */

require_once __DIR__.'/../update.php';
require_once __DIR__.'/../update/distro.php';

class Motd
{
    // TODO(complexity-refactor): Shell-outs and substitution are intertwined. (GH #126)
    // Extract IO (shell/system reads) from formatting, and isolate template
    // replacements into a pure function to simplify testing and reduce paths.
    public static function motdGenerate(): void
    {
        $tplPath = getenv('PMSS_MOTD_TEMPLATE_PATH') ?: '/etc/seedbox/config/template.motd';
        $outPath = getenv('PMSS_MOTD_OUTPUT_PATH') ?: '/etc/motd';
        $tpl      = @file_get_contents($tplPath);
        if ($tpl === false) return;

        [$host,$ip,$cpu,$ram,$storage] = self::sysBasics();
        [$pmssVersion,$updateDate] = self::versionInfo();
        [$uptime,$kernel,$netSpeed] = self::runtimeInfo();
        $distro = self::distroInfo();
        // Light color accents for readability (opt-out via PMSS_MOTD_COLOR=0)
        if (self::colorEnabled()) {
            $host       = self::c($host, '1;36');   // bold cyan
            $ip         = self::c($ip, '32');       // green
            $cpu        = self::c($cpu, '37');      // white
            $ram        = self::c($ram, '36');      // cyan
            $storage    = self::c($storage, '35');  // magenta
            $pmssVersion= self::c($pmssVersion, '1;34'); // bold blue
            $kernel     = self::c($kernel, '34');   // blue
            $distro     = self::c($distro, '1;35'); // bold magenta
            $ns = trim($netSpeed);
            $netSpeed   = ($ns !== '' && strcasecmp($ns, 'unknown') !== 0 && strcasecmp($ns, 'n/a') !== 0)
                ? self::c($ns, '32')                // green when detected
                : self::c('Unknown', '33');         // yellow when unknown
        }
        [$wg,$ovpn] = self::serviceStatuses();
        $storageWarn = self::storageWarnings();

        $repl = [
            '%HOSTNAME%'        => $host,
            '%SERVER_IP%'       => $ip,
            '%SERVER_CPU%'      => $cpu,
            '%SERVER_RAM%'      => $ram,
            '%SERVER_STORAGE%'  => $storage,
            '%PMSS_VERSION%'    => $pmssVersion,
            '%UPDATE_DATE%'     => $updateDate,
            '%APT_LAST_UPDATE%' => self::aptLastUpdate(),
            '%UPTIME%'          => $uptime,
            '%KERNEL_VERSION%'  => $kernel,
            '%NETWORK_SPEED%'   => $netSpeed,
            '%WIREGUARD_STATUS%'=> $wg,
            '%OPENVPN_STATUS%'  => $ovpn,
            '%DISTRO%'          => $distro,
        ];
        foreach ($repl as $k => $v) $tpl = str_replace($k, $v, $tpl);
        
        // Clean up lines that might remain if the template still has %RUN_VERSION%
        $tpl = str_replace('Runtime Version: %RUN_VERSION%', '', $tpl);
        $tpl = preg_replace('/^\s*Runtime Version:.*$/m', '', $tpl);

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
        
        // Append short commit hash if available
        $metaPath = '/etc/seedbox/config/version.meta';
        if (is_file($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            if (isset($meta['commit']) && strlen($meta['commit']) >= 7) {
                $pmssVersion .= ' ('.substr($meta['commit'], 0, 7).')';
            }
        }

        $updateDate = is_file('/var/run/pmss/updated') ? trim((string) @file_get_contents('/var/run/pmss/updated')) : 'not set';
        return [$pmssVersion,$updateDate];
    }

    private static function aptLastUpdate(): string
    {
        $f = '/var/lib/apt/periodic/update-success-stamp';
        if (!is_file($f)) return 'Not available';
        $ts = @filemtime($f);
        if ($ts === false || $ts <= 0) return 'Not available';
        // Show date only to avoid noisy fractional seconds/timezones
        return date('Y-m-d', $ts);
    }

    private static function runtimeInfo(): array
    {
        $uptime  = trim((string) shell_exec('uptime -p'));
        $kernel  = trim((string) shell_exec('uname -r'));
        // Discover the primary interface via routing table
        $iface = self::parseIfaceFromRoute(trim((string) shell_exec('ip -o route get 1 2>/dev/null')));
        if ($iface === '') {
            $iface = self::parseIfaceFromRoute(trim((string) shell_exec('ip route show default 2>/dev/null')));
        }
        $net = 'Unknown';
        if ($iface !== '') {
            // Prefer sysfs when available
            $sysSpeed = "/sys/class/net/".$iface."/speed";
            if (@is_file($sysSpeed)) {
                $val = trim((string) @file_get_contents($sysSpeed));
                if ($val !== '' && ctype_digit(str_replace(['-','+'], '', $val))) {
                    $intVal = (int) $val;
                    if ($intVal > 0) {
                        $net = $intVal.'Mb/s';
                    }
                }
            }
            // Fallback to ethtool on detected interface
            if ($net === 'Unknown') {
                $nsRaw = shell_exec('ethtool '.escapeshellarg($iface)." 2>/dev/null | grep 'Speed:'");
                if ($nsRaw && preg_match('/Speed:\\s+(\\S+)/', $nsRaw, $m)) {
                    $net = $m[1];
                }
            }
        }
        return [$uptime,$kernel,$net];
    }

    private static function colorEnabled(): bool
    {
        $v = getenv('PMSS_MOTD_COLOR');
        // Default to enabled; allow explicit opt-out
        if ($v === false || $v === '') return true;
        $v = strtolower((string) $v);
        return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
    }

    private static function c(string $text, string $code): string
    {
        return "\e[{$code}m{$text}\e[0m";
    }

    private static function parseIfaceFromRoute(string $line): string
    {
        if ($line === '') return '';
        $parts = preg_split('/\s+/', $line);
        if (!$parts) return '';
        $count = count($parts);
        for ($idx = 0; $idx < $count; $idx++) {
            if ($parts[$idx] === 'dev' && isset($parts[$idx + 1])) {
                return $parts[$idx + 1];
            }
        }
        return '';
    }

    private static function distroInfo(): string
    {
        // Prefer codename mapping to ensure correct major version
        $info = \pmssDetectDistro();
        $name = $info['name'] ?? '';
        $ver  = (int) ($info['version'] ?? 0);
        $code = $info['codename'] ?? '';
        if ($name === '') $name = 'debian';
        $name = ucfirst(strtolower($name));
        if ($ver > 0 && $code !== '') return sprintf('%s %d (%s)', $name, $ver, $code);
        if ($ver > 0) return sprintf('%s %d', $name, $ver);
        if ($code !== '') return sprintf('%s (%s)', $name, $code);
        return $name;
    }

    private static function serviceStatuses(): array
    {
        $svc = static function (string $service, ?string $configPath): string {
            if ($configPath !== null && !file_exists($configPath)) {
                return self::c('not configured', '33');
            }
            if (!is_dir('/run/systemd/system')) return self::c('unknown', '33');
            exec('systemctl is-active --quiet '.escapeshellarg($service), $o, $rc);
            if ($rc === 0) return self::c('active', '32');
            exec('systemctl is-enabled --quiet '.escapeshellarg($service), $o, $en);
            return $en !== 0 ? self::c('disabled', '33') : self::c('inactive', '31');
        };
        return [
            $svc('wg-quick@wg0', '/etc/wireguard/wg0.conf'),
            $svc('openvpn@openvpn', '/etc/openvpn/openvpn.conf'),
        ];
    }

    private static function storageWarnings(): string
    {
        $path = getenv('PMSS_HEALTH_LOG_PATH') ?: '/var/log/pmss/storage-health.jsonl';
        if (!is_file($path)) return '';
        $fh = @fopen($path,'r'); if (!$fh) return '';
        $raidWarn = null; $nvmeCrit=[]; $lastSmart=[]; $raidPerf=null;
        while (($line=fgets($fh))!==false) {
            $j = json_decode($line,true); if (!is_array($j)) continue;
            $k = $j['kind'] ?? '';
            if ($k==='smart') { $lastSmart[$j['device'] ?? '']=$j; }
            elseif ($k==='raid') {
                if (($j['severity'] ?? 'ok')!=='ok') $raidWarn=$j;
                if (in_array('rebuild_in_progress', (array)($j['flags'] ?? []), true)) {
                    $raidPerf = 'RAID '.($j['array'] ?? 'md').' resync in progress';
                }
            }
            elseif ($k==='nvme') { if ((int)($j['metrics']['critical_warnings'] ?? 0) > 0) $nvmeCrit[] = $j['device'] ?? 'nvme'; }
        }
        fclose($fh);
        $lines=[];
        if ($raidWarn) {
            $arr=$raidWarn['array'] ?? 'md'; $flags=implode(',',(array)($raidWarn['flags']??[]));
            $lines[] = "RAID $arr: ".($flags!==''?$flags:($raidWarn['state']??'warn'));
        }
        if ($raidPerf) $lines[] = 'Performance limited: '.$raidPerf;
        if (!empty($nvmeCrit)) $lines[] = 'NVMe critical warning: '.implode(', ', array_unique($nvmeCrit));
        foreach ($lastSmart as $dev=>$s) {
            if (in_array('udma_crc_increase',(array)($s['flags']??[]),true)) $lines[] = 'SATA UDMA CRC increased: '.$dev;
        }
        return implode(' | ', $lines);
    }
}
