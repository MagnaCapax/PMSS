<?php
/** Account-owned Hermes dashboard for Pikkumies. No root operation enters this tree. */
require_once __DIR__.'/pmssAccountAgent.php';
require_once __DIR__.'/pmssHermesRelay.php';

function pmssPikkumiesHermesPaths(string $home): array
{
    $base = $home.'/.mcxPikkumies/pikkumies/local/ui/hermes';
    return array('home' => $home, 'base' => $base,
        'binary' => $home.'/.mcxPikkumies/pikkumies/bin/pikkumies',
        'port' => $home.'/.media-stack-port-pikkumies-hermes',
        'credentials' => $base.'/credentials', 'pid' => $base.'/dashboard.pid',
        'socket' => $base.'/ui.sock', 'relayPid' => $base.'/relay.pid');
}

/** Only two fixed, shell-safe assignments may enter the clean child environment. */
function pmssPikkumiesHermesCredentials(array $paths): ?array
{
    $file = $paths['credentials'];
    if (!pmssAgentPrivateFileIsSafe($file, 256)) return null;
    $raw = (string) @file_get_contents($file);
    if (!preg_match('/^HERMES_DASHBOARD_BASIC_AUTH_PASSWORD=([a-f0-9]{64})\nHERMES_DASHBOARD_BASIC_AUTH_SECRET=([a-f0-9]{64})\n$/D', $raw, $match)) return null;
    return array($match[1], $match[2]);
}

function pmssPikkumiesHermesWrite(array $paths, string $password, string $secret): bool
{
    $base = $paths['base'];
    if (is_link($base) || (!is_dir($base) && !@mkdir($base, 0700, true))) return false;
    $temp = @tempnam($base, '.hermes-login-');
    if ($temp === false) return false;
    $content = 'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD='.$password."\n"
        .'HERMES_DASHBOARD_BASIC_AUTH_SECRET='.$secret."\n";
    $ok = @chmod($temp, 0600) && @file_put_contents($temp, $content) === strlen($content)
        && !is_link($paths['credentials']) && @rename($temp, $paths['credentials']);
    if (!$ok) @unlink($temp);
    return $ok;
}

/** A direct loopback request must encounter Hermes' login before the route opens. */
function pmssPikkumiesHermesGate(int $port): bool
{
    $context = stream_context_create(array('http' => array('timeout' => 2, 'ignore_errors' => true,
        'follow_location' => 0, 'header' => "Host: 127.0.0.1\r\n")));
    @file_get_contents('http://127.0.0.1:'.$port.'/api/auth/me', false, $context);
    return preg_match('/^HTTP\/1\.[01] 401(?: |$)/', $http_response_header[0] ?? '') === 1;
}

/** A stale dashboard is stopped only when its command line identifies it. */
function pmssPikkumiesHermesStopUngated(int $port): bool
{
    $pid = pmssAgentTcpListenerPid($port);
    if ($pid === null) return false;
    $cmd = @file_get_contents('/proc/'.$pid.'/cmdline');
    if (!is_string($cmd) || strpos($cmd, 'dashboard') === false
        || (strpos($cmd, 'hermes') === false && strpos($cmd, 'pikkumies') === false)) return false;
    if (!@posix_kill($pid, SIGTERM)) return false;
    for ($i = 0; $i < 20; ++$i) {
        if (pmssAgentTcpListenerPid($port) !== $pid) return true;
        usleep(100000);
    }
    $still = @file_get_contents('/proc/'.$pid.'/cmdline');
    if (pmssAgentTcpListenerPid($port) === $pid && is_string($still)
        && strpos($still, 'dashboard') !== false) @posix_kill($pid, SIGKILL);
    return pmssAgentTcpListenerPid($port) !== $pid;
}

function pmssPikkumiesHermesReady(array $paths): bool
{
    $port = pmssAgentReservedPort($paths['port']);
    return $port !== null && pmssAgentTcpListenerPid($port) !== null
        && pmssPikkumiesHermesGate($port) && pmssHermesRelayReady($paths['socket']);
}

/** Stop the recorded child even if ss cannot inspect its listener. */
function pmssPikkumiesHermesStop(array $paths): void
{
    pmssHermesRelayStop($paths['relayPid'], $paths['socket']);
    clearstatcache(true, $paths['pid']);
    $raw = is_file($paths['pid']) && !is_link($paths['pid']) ? trim((string) @file_get_contents($paths['pid'])) : '';
    if (preg_match('/^[1-9][0-9]*$/D', $raw)) {
        $proc = '/proc/'.$raw;
        $cmd = @file_get_contents($proc.'/cmdline');
        if (@fileowner($proc) === posix_geteuid() && is_string($cmd)
            && strpos($cmd, 'dashboard') !== false
            && (strpos($cmd, 'hermes') !== false || strpos($cmd, 'pikkumies') !== false)) {
            @posix_kill((int) $raw, SIGTERM);
            for ($i = 0; $i < 20; ++$i) {
                $still = @file_get_contents($proc.'/cmdline');
                if (@fileowner($proc) !== posix_geteuid() || !is_string($still)
                    || strpos($still, 'dashboard') === false) break;
                usleep(100000);
            }
            $still = @file_get_contents($proc.'/cmdline');
            if (@fileowner($proc) === posix_geteuid() && is_string($still)
                && strpos($still, 'dashboard') !== false) @posix_kill((int) $raw, SIGKILL);
        }
    }
    $port = pmssAgentReservedPort($paths['port']);
    if ($port !== null && pmssAgentTcpListenerPid($port) !== null) pmssPikkumiesHermesStopUngated($port);
}

/** The account's cron tick removes any owned listener that lost its login gate. */
function pmssPikkumiesHermesEnsure(array $paths): bool
{
    $port = pmssAgentReservedPort($paths['port']);
    if ($port === null || pmssAgentCommandPath('ss') === '') {
        pmssPikkumiesHermesStop($paths);
        return false;
    }
    if (pmssAgentTcpListenerPid($port) === null) {
        pmssHermesRelayStop($paths['relayPid'], $paths['socket']);
        return true;
    }
    if (pmssPikkumiesHermesGate($port) && pmssHermesRelayReady($paths['socket'])) return true;
    pmssPikkumiesHermesStop($paths);
    return false;
}
