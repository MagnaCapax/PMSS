<?php
/** Collect named MOTD fields directly, without intermediate positional records. */

require_once __DIR__.'/../update/distro.php';
require_once __DIR__.'/../version.php';
require_once __DIR__.'/network.php';
require_once __DIR__.'/health.php';

/** @return array<string,string> System inputs in the renderer's existing model shape. */
function pmssMotdModelCollect(): array
{
    // Preserve probe order: basics, version, runtime, distro, services, health, apt.
    $model['host'] = pmssHostnameRead();
    $model['ip']   = gethostbyname($model['host']);
    $model['cpu']  = trim((string) shell_exec("lscpu | grep 'Model name:' | sed 's/Model name:\\s*//'"));
    $model['ram']  = trim((string) shell_exec("free -h | awk '/^Mem:/ { print \$2 }'"));
    $model['storage'] = trim((string) shell_exec("df -h /home | awk 'NR==2 {print \$2}'"));

    $model['pmssVersion'] = getPmssVersion();

    // Append short commit hash if available
    $meta = pmssJsonFileReadAssoc('/etc/seedbox/config/version.meta');
    if (is_array($meta) && isset($meta['commit']) && strlen($meta['commit']) >= 7) {
        $model['pmssVersion'] .= ' ('.substr($meta['commit'], 0, 7).')';
    }

    $model['updateDate'] = pmssReadRegularFileTrimmed('/var/run/pmss/updated') ?? 'not set';

    $model['uptime'] = trim((string) shell_exec('uptime -p'));
    $model['kernel'] = trim((string) shell_exec('uname -r'));
    $model['netSpeed'] = pmssMotdNetworkSpeed();
    $info = \pmssDetectDistro();
    $name = (string) ($info['name'] ?? '');
    $ver  = (int) ($info['version'] ?? 0);
    $code = (string) ($info['codename'] ?? '');
    if ($name === '') $name = 'debian';
    $name = ucfirst(strtolower($name));
    $model['distro'] = $name.($ver > 0 ? ' '.$ver : '').($code !== '' ? ' ('.$code.')' : '');
    $svc = static function (string $service, ?string $configPath): string {
        if ($configPath !== null && !file_exists($configPath)) {
            return "\e[33mnot configured\e[0m";
        }
        $active = \pmssSystemdUnitIsActive($service);
        if ($active === null) return "\e[33munknown\e[0m";
        if ($active) return "\e[32mactive\e[0m";
        return \pmssSystemdUnitIsEnabled($service) === false ? "\e[33mdisabled\e[0m" : "\e[31minactive\e[0m";
    };
    $model['wgStatus'] = $svc('wg-quick@wg0', '/etc/wireguard/wg0.conf');
    $model['ovpnStatus'] = $svc('openvpn@openvpn', '/etc/openvpn/openvpn.conf');
    $model['storageWarn'] = pmssMotdStorageWarnings();
    $aptUpdateStamp = @filemtime('/var/lib/apt/periodic/update-success-stamp');
    $model['aptLastUpdate'] = ($aptUpdateStamp === false || $aptUpdateStamp <= 0)
        ? 'Not available' : date('Y-m-d', $aptUpdateStamp);
    return $model;
}
