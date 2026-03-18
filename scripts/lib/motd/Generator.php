<?php
/**
 * Library helper: Generator.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** MOTD generator (class-based). */

require_once __DIR__.'/../update/distro.php';

class Motd
{
    /**
     * Controller: generate + write MOTD from the template.
     *
     * MVC split:
     * - Model: motdCollectModel() gathers system inputs (shell-outs, files).
     * - View:  renderMotdTemplate() formats + substitutes placeholders (pure).
     * - Ctrl:  motdGenerate() orchestrates IO + writes output.
     */
    public static function motdGenerate(): void
    {
        $tplPath = getenv('PMSS_MOTD_TEMPLATE_PATH') ?: '/etc/seedbox/config/template.motd';
        $outPath = getenv('PMSS_MOTD_OUTPUT_PATH') ?: '/etc/motd';
        $tpl = @file_get_contents($tplPath);
        if (!is_string($tpl)) {
            return;
        }

        $model = self::motdCollectModel();
        $colorEnabled = getenv('PMSS_MOTD_COLOR');
        $rendered = self::renderMotdTemplate(
            $tpl,
            $model,
            ($colorEnabled === false || $colorEnabled === '')
                ? true
                : in_array(strtolower((string) $colorEnabled), ['1', 'true', 'yes', 'on'], true)
        );
        file_put_contents($outPath, $rendered);
        @chmod($outPath, 0644);
        @chown($outPath, 'root');
        @chgrp($outPath, 'root');

        // Align PAM motd behavior so users see MOTD once (and non-root can read it).
        if ($outPath === '/etc/motd') {
            self::motdSyncPamDynamic($rendered);
        }
    }

    /**
     * Model: gather system inputs.
     *
     * @return array<string, string>
     */
    private static function motdCollectModel(): array
    {
        [$host, $ip, $cpu, $ram, $storage] = self::sysBasics();
        [$pmssVersion, $updateDate] = self::versionInfo();
        [$uptime, $kernel, $netSpeed] = self::runtimeInfo();
        $distro = self::distroInfo();
        [$wg, $ovpn] = self::serviceStatuses();
        $storageWarn = self::storageWarnings();
        $aptUpdateStamp = @filemtime('/var/lib/apt/periodic/update-success-stamp');

        return [
            'host'          => (string) $host,
            'ip'            => (string) $ip,
            'cpu'           => (string) $cpu,
            'ram'           => (string) $ram,
            'storage'       => (string) $storage,
            'pmssVersion'   => (string) $pmssVersion,
            'updateDate'    => (string) $updateDate,
            'aptLastUpdate' => ($aptUpdateStamp === false || $aptUpdateStamp <= 0)
                ? 'Not available'
                : date('Y-m-d', $aptUpdateStamp),
            'uptime'        => (string) $uptime,
            'kernel'        => (string) $kernel,
            'netSpeed'      => (string) $netSpeed,
            'wgStatus'      => (string) $wg,
            'ovpnStatus'    => (string) $ovpn,
            'distro'        => (string) $distro,
            'storageWarn'   => (string) $storageWarn,
        ];
    }

    /**
     * View: render the MOTD template from a pre-collected model.
     *
     * This function is pure: it must not perform shell-outs or read files.
     *
     * @param array<string, string> $model
     */
    public static function renderMotdTemplate(string $template, array $model, bool $colorEnabled): string
    {
        $host = self::motdModelValue($model, 'host');
        $ip = self::motdModelValue($model, 'ip');
        $cpu = self::motdModelValue($model, 'cpu');
        $ram = self::motdModelValue($model, 'ram');
        $storage = self::motdModelValue($model, 'storage');
        $pmssVersion = self::motdModelValue($model, 'pmssVersion');
        $updateDate = self::motdModelValue($model, 'updateDate');
        $aptLastUpdate = self::motdModelValue($model, 'aptLastUpdate');
        $uptime = self::motdModelValue($model, 'uptime');
        $kernel = self::motdModelValue($model, 'kernel');
        $netSpeed = self::motdModelValue($model, 'netSpeed');
        $wg = self::motdModelValue($model, 'wgStatus');
        $ovpn = self::motdModelValue($model, 'ovpnStatus');
        $distro = self::motdModelValue($model, 'distro');

        // Light color accents for readability (opt-out via PMSS_MOTD_COLOR=0)
        if ($colorEnabled) {
            $host = self::c($host, '1;36'); // bold cyan
            $ip = self::c($ip, '32'); // green
            $cpu = self::c($cpu, '37'); // white
            $ram = self::c($ram, '36'); // cyan
            $storage = self::c($storage, '35'); // magenta
            $pmssVersion = self::c($pmssVersion, '1;34'); // bold blue
            $kernel = self::c($kernel, '34'); // blue
            $distro = self::c($distro, '1;35'); // bold magenta
            $netSpeed = trim($netSpeed);
            $netSpeed = ($netSpeed !== '' && !in_array(strtolower($netSpeed), ['unknown', 'n/a'], true))
                ? self::c($netSpeed, '32') // green when detected
                : self::c('Unknown', '33'); // yellow when unknown
        }

        $repl = [
            '%HOSTNAME%'         => $host,
            '%SERVER_IP%'        => $ip,
            '%SERVER_CPU%'       => $cpu,
            '%SERVER_RAM%'       => $ram,
            '%SERVER_STORAGE%'   => $storage,
            '%PMSS_VERSION%'     => $pmssVersion,
            '%UPDATE_DATE%'      => $updateDate,
            '%APT_LAST_UPDATE%'  => $aptLastUpdate,
            '%UPTIME%'           => $uptime,
            '%KERNEL_VERSION%'   => $kernel,
            '%NETWORK_SPEED%'    => $netSpeed,
            '%WIREGUARD_STATUS%' => $wg,
            '%OPENVPN_STATUS%'   => $ovpn,
            '%DISTRO%'           => $distro,
        ];

        $rendered = strtr($template, $repl);
        $rendered = str_replace('Runtime Version: %RUN_VERSION%', '', $rendered);
        $patched = preg_replace('/^\s*Runtime Version:.*$/m', '', $rendered);
        $rendered = is_string($patched) ? $patched : $rendered;

        $storageWarn = self::motdModelValue($model, 'storageWarn');
        if ($storageWarn !== '') {
            $rendered .= "\n\e[33mStorage WARN:\e[0m ".$storageWarn."\n";
        }

        return $rendered;
    }

    /**
     * @param array<string, string> $model
     */
    private static function motdModelValue(array $model, string $key): string
    {
        if (!isset($model[$key])) {
            return '';
        }
        $value = $model[$key];
        return is_string($value) ? $value : (string) $value;
    }

    /**
     * Mirror the rendered MOTD into /run/motd.dynamic when PAM is configured for it.
     */
    private static function motdSyncPamDynamic(string $rendered): void
    {
        $data = @file_get_contents('/etc/pam.d/sshd');
        $usesDynamic = false;
        $usesStatic = false;
        if (is_string($data)) {
            foreach (preg_split('/\r?\n/', $data) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (strpos($line, 'pam_motd.so') === false) {
                    continue;
                }
                if (strpos($line, 'motd=/run/motd.dynamic') !== false) {
                    $usesDynamic = true;
                    continue;
                }
                $usesStatic = true;
            }
        }
        $dynamicPath = '/run/motd.dynamic';
        if ($usesDynamic && !$usesStatic) {
            file_put_contents($dynamicPath, $rendered);
            @chmod($dynamicPath, 0644);
            @chown($dynamicPath, 'root');
            @chgrp($dynamicPath, 'root');
        } elseif ($usesDynamic && $usesStatic) {
            file_put_contents($dynamicPath, '');
            @chmod($dynamicPath, 0644);
            @chown($dynamicPath, 'root');
            @chgrp($dynamicPath, 'root');
        }
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

    private static function runtimeInfo(): array
    {
        $uptime  = trim((string) shell_exec('uptime -p'));
        $kernel  = trim((string) shell_exec('uname -r'));
        // Discover the primary interface via routing table
        $route = preg_split('/\s+/', trim((string) shell_exec('ip -o route get 1 2>/dev/null')));
        $ifaceIndex = is_array($route) ? array_search('dev', $route, true) : false;
        $iface = ($ifaceIndex !== false && isset($route[$ifaceIndex + 1])) ? $route[$ifaceIndex + 1] : '';
        if ($iface === '') {
            $route = preg_split('/\s+/', trim((string) shell_exec('ip route show default 2>/dev/null')));
            $ifaceIndex = is_array($route) ? array_search('dev', $route, true) : false;
            $iface = ($ifaceIndex !== false && isset($route[$ifaceIndex + 1])) ? $route[$ifaceIndex + 1] : '';
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

    private static function c(string $text, string $code): string
    {
        return "\e[{$code}m{$text}\e[0m";
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
