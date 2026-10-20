<?php
/** Account-owned Pikkumies Hermes launch and session lifecycle. */
require_once __DIR__.'/pikkumiesHermesAuth.php';

function pmssPikkumiesHermesSchedule(array $paths): bool
{
    $line = '* * * * * HOME='.escapeshellarg($paths['home']).' /usr/bin/php '
        .escapeshellarg($paths['home'].'/bin/pikkumiesHermesUi.php').' ensure >/dev/null 2>&1';
    try {
        pmssAgentCronApply($paths['home'], '# pmss-pikkumies-hermes', array($line));
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

/** Build a command line that contains no credential bytes. */
function pmssPikkumiesHermesCommand(array $paths, string $origin, int $port): string
{
    $script = '. "$1"; export HERMES_DASHBOARD_BASIC_AUTH_PASSWORD HERMES_DASHBOARD_BASIC_AUTH_SECRET; '
        .'exec "$2" run dashboard --host 127.0.0.1 --port "$3" --no-open --skip-build';
    return 'nohup /usr/bin/env -i HOME='.escapeshellarg($paths['home'])
        .' PATH=/usr/local/bin:/usr/bin:/bin HERMES_DASHBOARD_PUBLIC_URL='.escapeshellarg($origin.'/')
        .' HERMES_DASHBOARD_BASIC_AUTH_USERNAME='.escapeshellarg(basename($paths['home']))
        .' /bin/sh -c '.escapeshellarg($script).' sh '.escapeshellarg($paths['credentials'])
        .' '.escapeshellarg($paths['binary']).' '.escapeshellarg((string) $port)
        .' </dev/null >>'.escapeshellarg($paths['base'].'/dashboard.log').' 2>&1 & echo $!';
}

/** Start through env -i; credentials are sourced from a validated 0600 file. */
function pmssPikkumiesHermesStart(array $paths, string $origin): bool
{
    if (!preg_match('#^https://[0-9a-f]{16}\.mcx\.fi:845$#D', $origin)
        || !is_file($paths['binary']) || is_link($paths['binary']) || !is_executable($paths['binary'])) return false;
    $port = pmssAgentReservedPort($paths['port']);
    if ($port === null) return false;
    $credentials = pmssPikkumiesHermesCredentials($paths);
    if ($credentials === null) {
        if (file_exists($paths['credentials']) || is_link($paths['credentials'])
            || !pmssPikkumiesHermesWrite($paths, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)))) return false;
    }
    if (!pmssPikkumiesHermesSchedule($paths) || pmssAgentCommandPath('ss') === '') return false;
    pmssPikkumiesHermesEnsure($paths);
    if (pmssPikkumiesHermesReady($paths)) return true;
    // A foreign or ungated listener is never reused.
    if (pmssPikkumiesHermesListenerOwned($port)) {
        pmssPikkumiesHermesStopUngated($port);
        return false;
    }
    $command = pmssPikkumiesHermesCommand($paths, $origin, $port);
    $rows = array(); exec($command, $rows, $rc);
    $pid = trim((string) ($rows[0] ?? ''));
    if ($rc !== 0 || !preg_match('/^[1-9][0-9]*$/D', $pid)) return false;
    @file_put_contents($paths['pid'], $pid."\n");
    @file_put_contents($paths['base'].'/dashboard.port', $port."\n");
    for ($i = 0; $i < 100; ++$i) {
        if (pmssPikkumiesHermesListenerOwned($port) && pmssPikkumiesHermesGate($port)) {
            if (pmssHermesRelayStart($paths['home'], $paths['relayPid'], $paths['socket'], $port)) return true;
            break;
        }
        usleep(100000);
    }
    pmssPikkumiesHermesStop($paths);
    return false;
}

/** Stop the old child before reading credentials; replace both independent values. */
function pmssPikkumiesHermesRotate(array $paths): bool
{
    pmssPikkumiesHermesStop($paths);
    $credentials = pmssPikkumiesHermesCredentials($paths);
    if ($credentials === null) return !file_exists($paths['credentials']);
    return pmssPikkumiesHermesWrite($paths, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)));
}

/** Stop only the account's pinned simplified UI processes on logout. */
function pmssPikkumiesSimplifiedStop(array $paths): void
{
    $ui = dirname($paths['base']);
    foreach (glob($ui.'/ui-*/ui.pid') ?: array() as $file) {
        if (!is_file($file) || is_link($file)) continue;
        $pid = trim((string) @file_get_contents($file));
        if (!preg_match('/^[1-9][0-9]*$/D', $pid)) continue;
        $proc = '/proc/'.$pid;
        $cmd = @file_get_contents($proc.'/cmdline');
        if (@fileowner($proc) === posix_geteuid() && is_string($cmd)
            && strpos($cmd, '/opt/pikkumies-agentboard/agentboard') !== false) @posix_kill((int) $pid, SIGTERM);
    }
}
