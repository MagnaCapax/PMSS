<?php
/** Account-owned vanilla Hermes runtime. */
require_once __DIR__.'/pmssAccountAgent.php';

function pmssHermesPaths(string $home): array
{
    $base = $home.'/.hermes';
    return array('home' => $home, 'base' => $base, 'tree' => $base.'/hermes-agent',
        'exe' => $home.'/.local/bin/hermes', 'env' => $base.'/pmss-dashboard.env',
        'port' => $base.'/pmss-dashboard.port', 'pid' => $base.'/pmss-dashboard.pid',
        'disabled' => $base.'/pmss-dashboard.disabled');
}

/** Keep the independent service credentials in a private account file. */
function pmssHermesEnv(array $paths, string $publicUrl): array
{
    $file = $paths['env'];
    if (!is_file($file) || is_link($file) || fileowner($file) !== posix_geteuid()
        || (fileperms($file) & 0077) !== 0 || filesize($file) > 2048) throw new RuntimeException('Dashboard credential file is absent or insecure');
    $raw = (string) file_get_contents($file);
    if (!preg_match('/^HERMES_DASHBOARD_BASIC_AUTH_PASSWORD=([a-f0-9]{64})\nHERMES_DASHBOARD_BASIC_AUTH_SECRET=([a-f0-9]{64})\n?$/D', $raw, $m))
        throw new RuntimeException('Dashboard credential file is invalid');
    return array('HOME' => $paths['home'], 'PATH' => '/usr/local/bin:/usr/bin:/bin',
        'HERMES_HOME' => $paths['base'], 'HERMES_DASHBOARD_PUBLIC_URL' => $publicUrl,
        'HERMES_GATEWAY_LOCK_DIR' => $paths['base'].'/locks',
        'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD' => $m[1],
        'HERMES_DASHBOARD_BASIC_AUTH_SECRET' => $m[2]);
}

/** A PID only belongs to this installer if UID and command line match its tree. */
function pmssHermesRunningPid(array $paths): ?int
{
    $raw = is_file($paths['pid']) && !is_link($paths['pid']) ? trim((string) file_get_contents($paths['pid'])) : '';
    if (!preg_match('/^[1-9][0-9]*$/D', $raw)) return null;
    $proc = '/proc/'.$raw;
    $cmd = @file_get_contents($proc.'/cmdline');
    if (@fileowner($proc) !== posix_geteuid() || !is_string($cmd)
        || strpos($cmd, $paths['tree']) === false || strpos($cmd, 'dashboard') === false) return null;
    return (int) $raw;
}

function pmssHermesStop(array $paths): void
{
    $pid = pmssHermesRunningPid($paths);
    if ($pid === null) return;
    posix_kill($pid, SIGTERM);
    for ($i = 0; $i < 20 && pmssHermesRunningPid($paths) === $pid; ++$i) usleep(100000);
    if (pmssHermesRunningPid($paths) === $pid) posix_kill($pid, SIGKILL);
}

/** A direct loopback request must be denied before the proxy route opens. */
function pmssHermesGateProbe(int $port): bool
{
    $context = stream_context_create(array('http' => array('timeout' => 2, 'ignore_errors' => true,
        'follow_location' => 0, 'header' => "Host: 127.0.0.1\r\n")));
    $body = @file_get_contents('http://127.0.0.1:'.$port.'/api/auth/me', false, $context);
    $status = $http_response_header[0] ?? '';
    return $body !== false && preg_match('/^HTTP\/1\.[01] 401(?: |$)/', $status) === 1;
}

/** Match the core permalink derivation using only the root-owned identity. */
function pmssHermesPublicUrl(string $home): string
{
    $path = '/var/lib/pmss/public/billing/'.basename($home);
    $stat = @lstat($path);
    $id = is_array($stat) && ($stat['mode'] & 0170000) === 0100000
        && $stat['uid'] === 0 && $stat['nlink'] === 1 ? trim((string) @file_get_contents($path)) : '';
    if (!preg_match('/^[1-9][0-9]*$/D', $id)) throw new RuntimeException('Trusted service identity is unavailable');
    return 'https://'.substr(hash('sha256', 'mcx.fi:service:'.ltrim($id, '0')), 0, 16).'.mcx.fi:846/';
}

/** The listener's PID and HTTP gate are checked on every open. */
function pmssHermesListenerReady(array $paths, int $port): bool
{
    $pid = pmssHermesRunningPid($paths);
    if ($pid === null) return false;
    $rows = array();
    exec('/usr/bin/ss -H -ltnp '.escapeshellarg('sport = :'.$port).' 2>/dev/null', $rows, $rc);
    return $rc === 0 && strpos(implode("\n", $rows), 'pid='.$pid.',') !== false
        && pmssHermesGateProbe($port);
}

/** Spawn with only approved environment keys; no secret enters argv. */
function pmssHermesStart(array $paths): void
{
    if (!is_file($paths['exe']) || is_link($paths['exe'])) throw new RuntimeException('Hermes is not installed');
    $port = pmssAgentReservedPort($paths['home'].'/.media-stack-port-hermes');
    if ($port === null) throw new RuntimeException('No reserved Hermes port');
    if (is_link($paths['disabled']) || is_link($paths['port'])) throw new RuntimeException('Dashboard state is unsafe');
    $url = pmssHermesPublicUrl($paths['home']);
    if (pmssHermesRunningPid($paths) !== null) {
        if (pmssHermesListenerReady($paths, $port)) return;
        pmssHermesStop($paths);
        throw new RuntimeException('Dashboard login gate is unavailable');
    }
    $env = pmssHermesEnv($paths, $url);
    $log = $paths['base'].'/logs/pmss-dashboard.log';
    if (!is_dir(dirname($log))) mkdir(dirname($log), 0700, true);
    if (!is_dir($paths['base'].'/locks')) mkdir($paths['base'].'/locks', 0700);
    $command = 'nohup '.escapeshellarg($paths['exe']).' dashboard --host 127.0.0.1 --port '
        .escapeshellarg((string) $port).' --no-open --skip-build </dev/null >>'.escapeshellarg($log).' 2>&1 & echo $!';
    $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
    if (!is_resource($process)) throw new RuntimeException('Dashboard could not launch');
    fclose($pipes[0]); $pid = trim((string) stream_get_contents($pipes[1])); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    if (!preg_match('/^[1-9][0-9]*$/D', $pid)) throw new RuntimeException('Dashboard did not return a PID');
    file_put_contents($paths['pid'], $pid."\n");
    file_put_contents($paths['port'], $port."\n");
    for ($i = 0; $i < 150; ++$i) {
        if (pmssHermesListenerReady($paths, $port)) return;
        usleep(100000);
    }
    pmssHermesStop($paths);
    throw new RuntimeException('Dashboard login gate did not start');
}
