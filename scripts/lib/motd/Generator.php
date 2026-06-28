<?php
/**
 * Library helper: Generator.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** MOTD generator (class-based). */

require_once __DIR__.'/../update/distro.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../version.php';

class Motd
{
    /** Placeholder catalog: template token => model key plus optional ANSI color. */
    private const MOTD_FIELDS = [
        '%HOSTNAME%' => ['host', '1;36'], '%SERVER_IP%' => ['ip', '32'], '%SERVER_CPU%' => ['cpu', '37'], '%SERVER_RAM%' => ['ram', '36'],
        '%SERVER_STORAGE%' => ['storage', '35'], '%PMSS_VERSION%' => ['pmssVersion', '1;34'], '%UPDATE_DATE%' => ['updateDate'], '%APT_LAST_UPDATE%' => ['aptLastUpdate'],
        '%UPTIME%' => ['uptime'], '%KERNEL_VERSION%' => ['kernel', '34'], '%NETWORK_SPEED%' => ['netSpeed'], '%WIREGUARD_STATUS%' => ['wgStatus'],
        '%OPENVPN_STATUS%' => ['ovpnStatus'], '%DISTRO%' => ['distro', '1;35'],
    ];

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
        $tplPath = pmssResolvePathFromEnv('PMSS_MOTD_TEMPLATE_PATH', '/etc/seedbox/config/template.motd');
        $outPath = pmssResolvePathFromEnv('PMSS_MOTD_OUTPUT_PATH', '/etc/motd');
        $tpl = @file_get_contents($tplPath);
        if (!is_string($tpl)) {
            return;
        }

        $model = self::motdCollectModel();
        $colorEnabled = getenv('PMSS_MOTD_COLOR');
        $rendered = self::renderMotdTemplate(
            $tpl,
            $model,
            ($colorEnabled === false || $colorEnabled === '') ? true : pmssEnvValueIsTruthy($colorEnabled)
        );
        pmssWriteManagedFile($outPath, $rendered, 'root', 'root', 0644);

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
        $repl = [];
        foreach (self::MOTD_FIELDS as $placeholder => $field) {
            $value = isset($model[$field[0]]) ? (string) $model[$field[0]] : '';
            $repl[$placeholder] = $colorEnabled && isset($field[1]) ? self::c($value, $field[1]) : $value;
        }

        if ($colorEnabled) {
            $netSpeed = trim($repl['%NETWORK_SPEED%']);
            $repl['%NETWORK_SPEED%'] = ($netSpeed !== '' && !in_array(strtolower($netSpeed), ['unknown', 'n/a'], true))
                ? self::c($netSpeed, '32') // green when detected
                : self::c('Unknown', '33'); // yellow when unknown
        }

        $rendered = strtr($template, $repl);
        $patched = preg_replace('/^\s*Runtime Version:.*$/m', '', $rendered);
        $rendered = is_string($patched) ? $patched : $rendered;

        $storageWarn = isset($model['storageWarn']) ? (string) $model['storageWarn'] : '';
        if ($storageWarn !== '') {
            $rendered .= "\n\e[33mStorage WARN:\e[0m ".$storageWarn."\n";
        }

        return $rendered;
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
        if ($usesDynamic) {
            pmssWriteManagedFile('/run/motd.dynamic', $usesStatic ? '' : $rendered, 'root', 'root', 0644);
        }
    }

    private static function sysBasics(): array
    {
        $host = pmssHostnameRead();
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
        $meta = pmssJsonFileReadAssoc('/etc/seedbox/config/version.meta');
        if (is_array($meta) && isset($meta['commit']) && strlen($meta['commit']) >= 7) {
            $pmssVersion .= ' ('.substr($meta['commit'], 0, 7).')';
        }

        $updateDate = pmssReadRegularFileTrimmed('/var/run/pmss/updated') ?? 'not set';
        return [$pmssVersion,$updateDate];
    }

    private static function runtimeInfo(): array
    {
        $uptime  = trim((string) shell_exec('uptime -p'));
        $kernel  = trim((string) shell_exec('uname -r'));
        $iface = '';
        // Discover the primary interface via routing table, preferring route-get.
        foreach (['ip -o route get 1 2>/dev/null', 'ip route show default 2>/dev/null'] as $routeCommand) {
            $route = preg_split('/\s+/', trim((string) shell_exec($routeCommand)));
            $ifaceIndex = is_array($route) ? array_search('dev', $route, true) : false;
            $iface = ($ifaceIndex !== false && isset($route[$ifaceIndex + 1])) ? $route[$ifaceIndex + 1] : '';
            if ($iface !== '') {
                break;
            }
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
        $info = \pmssDetectDistro();
        $name = (string) ($info['name'] ?? '');
        $ver  = (int) ($info['version'] ?? 0);
        $code = (string) ($info['codename'] ?? '');
        if ($name === '') $name = 'debian';
        $name = ucfirst(strtolower($name));
        return $name.($ver > 0 ? ' '.$ver : '').($code !== '' ? ' ('.$code.')' : '');
    }

    private static function serviceStatuses(): array
    {
        $svc = static function (string $service, ?string $configPath): string {
            if ($configPath !== null && !file_exists($configPath)) {
                return self::c('not configured', '33');
            }
            $active = \pmssSystemdUnitIsActive($service);
            if ($active === null) return self::c('unknown', '33');
            if ($active) return self::c('active', '32');
            return \pmssSystemdUnitIsEnabled($service) === false ? self::c('disabled', '33') : self::c('inactive', '31');
        };
        return [
            $svc('wg-quick@wg0', '/etc/wireguard/wg0.conf'),
            $svc('openvpn@openvpn', '/etc/openvpn/openvpn.conf'),
        ];
    }

    private static function storageWarnings(): string
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

}
