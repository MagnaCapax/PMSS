#!/usr/bin/env php
<?php
/**
 * ADR 0114 sandbox checks. Never run on a production host or the build/control host.
 * --arg=RtorrentIpv4ConfigTest checks exact repairs without filesystem mutations.
 * --arg=Ipv4RuntimeTest --matrix=PATH checks already running sandbox fixtures.
 * Matrix keys are client@version from torrentClients.json, plus rclone@system,
 * rootless-docker@system and media-stack@system. Each row supplies account, pid,
 * binary and trace. The sandbox owns fixture launch/cleanup and workload generation.
 * Capture strace -f -e trace=socket,connect -p PID -o TRACE during an IPv4 positive
 * control AND an IPv6-only tracker/remote request made by that same client.
 * Trace files must retain PID prefixes. No process arguments or trace bytes are printed.
 * This checker cannot establish that the external workload exercised both requests;
 * the sandbox acceptance runner must retain that evidence beside the trace.
 * @license GPL-3.0-only
 */
require_once dirname(__DIR__).'/lib/rtorrent/configText.php';

/** A missing observation fails; it must never be interpreted as zero sockets. */
function pmssIpv4TestRequire(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

/** Empty, unrelated and IPv6-attempt traces cannot pass an outbound observation. */
function pmssIpv4RuntimeTraceIsIpv4(string $trace, int $pid): bool
{
    return $pid > 1 && strpos($trace, 'AF_INET6') === false
        && preg_match('/^(?:\[pid\s+)?'.$pid.'(?:\]\s*|\s+).*\bconnect\([^\n]*\bAF_INET\b/m', $trace) === 1;
}

/** Negative controls use syscall text only; no sockets or processes are opened. */
function pmssIpv4RuntimeTraceTest(): void
{
    $connect = '123 connect(3, {sa_family=AF_INET, sin_port=htons(80)}, 16) = -1 EINPROGRESS';
    foreach ([[$connect, 123, true], [$connect, 124, false], ['', 123, false],
        [$connect."\n123 socket(AF_INET6, SOCK_STREAM, 0) = -1 EAFNOSUPPORT", 123, false],
        [$connect."\n123 connect(4, {sa_family=AF_INET6}, 28) = -1 ENETUNREACH", 123, false],
        [str_replace('123 ', '[pid 123] ', $connect), 123, true], [$connect, 0, false]] as [$text, $pid, $expected]) {
        pmssIpv4TestRequire(pmssIpv4RuntimeTraceIsIpv4($text, $pid) === $expected, 'outbound trace control');
    }
}

/** Exact-line and idempotence controls, including CRLF, EOF, duplicates and customer edits. */
function pmssRtorrentIpv4ConfigTest(): void
{
    $legacy = 'branch = ((equal, ((system.client_version)), ((cat, "0.9.8")))), '
        .'((encoding.add, utf8)), ((network.bind_address.set, "0.0.0.0"))';
    $guard = 'branch = ((equal, ((system.client_version)), ((cat, "0.9.8")))), ((encoding.add, utf8)), ((cat))';
    $bind = 'network.bind_address.set = 0.0.0.0';
    $catch = 'catch = {"network.block.ipv6.set=yes"}';
    foreach (["", "\n", "\r\n"] as $ending) {
        $newline = $ending === "\r\n" ? $ending : "\n";
        $expected = $guard.$newline.$bind.$newline.$catch.$ending;
        pmssIpv4TestRequire(pmssRtorrentConfigIpv4Replace($legacy.$ending) === $expected, 'bind framing');
        pmssIpv4TestRequire(pmssRtorrentConfigIpv4Replace($expected) === $expected, 'bind idempotence');
        $raw = 'network.block.ipv6.set = yes'.$ending;
        pmssIpv4TestRequire(pmssRtorrentConfigIpv6CatchReplace($raw) === $catch.$ending, 'custom framing');
    }
    foreach (['', '# '.$legacy, ' '.$legacy, $legacy.' # edited', 'network.bind_address.set = 192.0.2.1'] as $raw) {
        pmssIpv4TestRequire(pmssRtorrentConfigIpv4Replace($raw) === $raw, 'preserve edited bind');
    }
    foreach (['', '# network.block.ipv6.set = yes', ' network.block.ipv6.set = yes',
        'network.block.ipv6.set = no', 'network.block.ipv6.set = yes # edited', $catch] as $raw) {
        pmssIpv4TestRequire(pmssRtorrentConfigIpv6CatchReplace($raw) === $raw, 'preserve edited custom');
    }
    $raw = "# retained\r\nnetwork.block.ipv6.set = yes\r\nnetwork.block.ipv6.set = yes\nother = kept";
    $expected = "# retained\r\n".$catch."\r\n".$catch."\nother = kept";
    pmssIpv4TestRequire(pmssRtorrentConfigIpv6CatchReplace($raw) === $expected, 'custom surrounding bytes');
    pmssIpv4TestRequire(pmssRtorrentConfigIpv6CatchReplace($expected) === $expected, 'custom idempotence');
}

/** Observe selected catalog builds and every account listener without launching or restarting daemons. */
function pmssIpv4RuntimeTest(string $matrixPath): void
{
    pmssIpv4TestRequire(getenv('PMSS_IPV4_TEST_SANDBOX') === '1', 'requires explicit sandbox environment');
    pmssIpv4TestRequire(function_exists('posix_geteuid') && posix_geteuid() === 0, 'requires sandbox root');
    require_once dirname(__DIR__).'/lib/rtorrent/scgi.php';
    require_once dirname(__DIR__, 2).'/etc/skel/www/torrentClientsResolve.php';
    $catalog = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/etc/seedbox/config/torrentClients.json'), true);
    $matrix = json_decode((string) @file_get_contents($matrixPath), true);
    pmssIpv4TestRequire(is_array($catalog) && $catalog !== [] && is_array($matrix), 'invalid catalog/matrix');
    $required = [];
    foreach ($catalog as $entry) {
        if (empty($entry['disabled'])) $required[$entry['client'].'@'.$entry['version']] = $entry;
    }
    foreach (['rclone', 'rootless-docker', 'media-stack'] as $client) {
        $required[$client.'@system'] = ['client' => $client, 'version' => 'system'];
    }
    $failed = 0;
    foreach ($required as $id => $entry) {
        try {
            $row = $matrix[$id] ?? [];
            $user = $row['account'] ?? '';
            pmssIpv4TestRequire(is_string($user) && preg_match('/\A[a-z][a-z0-9]{0,31}\z/', $user) === 1, 'missing sandbox account');
            $account = posix_getpwnam($user);
            pmssIpv4TestRequire(is_array($account) && $account['uid'] > 0, 'missing unprivileged identity');
            $pid = $row['pid'] ?? 0;
            pmssIpv4TestRequire(is_int($pid) && $pid > 1, 'missing fixture PID');
            $status = @file_get_contents('/proc/'.$pid.'/status');
            pmssIpv4TestRequire(is_string($status) && preg_match('/^Uid:\s+'.$account['uid'].'\s+'.$account['uid'].'\s/m', $status) === 1, 'PID ownership');
            $binary = $row['binary'] ?? '';
            pmssIpv4TestRequire(is_string($binary) && $binary !== '' && @readlink('/proc/'.$pid.'/exe') === realpath($binary), 'executable identity');
            if (in_array($entry['client'], ['rclone', 'rootless-docker'], true)) {
                pmssIpv4TestRequire(basename($binary) === ($entry['client'] === 'rclone' ? 'rclone' : 'rootlesskit'), 'component executable');
            }
            if (isset($entry['kind'])) {
                $launch = $entry['kind'] === 'system' ? $entry['binary'] : pmssTorrentClientResolve($account['dir'], $entry['client']);
                $expected = $entry['kind'] === 'system' ? $entry['binary'] : '/home/.pmss/clients/'.$entry['client'].'/'.$entry['version']
                    .($entry['client'] === 'deluge' ? '/bin/deluged' : '/'.['rtorrent' => 'rtorrent', 'qbittorrent' => 'qbittorrent-nox', 'transmission' => 'transmission-daemon'][$entry['client']]);
                pmssIpv4TestRequire($launch === $expected && is_file($launch), 'selected catalog build');
                // Python daemons expose their interpreter in /proc/exe; check the selected script's shebang too.
                $head = file_get_contents($launch, false, null, 0, 256);
                $interpreter = is_string($head) && preg_match('/\A#!([^\s]+)\s/', $head, $m) ? $m[1] : $launch;
                pmssIpv4TestRequire(realpath($interpreter) === realpath($binary), 'catalog executable/interpreter');
            }
            $listeners = []; $rc = 1;
            exec('ss -H -6 -lntupe 2>/dev/null', $listeners, $rc);
            pmssIpv4TestRequire($rc === 0, 'IPv6 socket observation failed');
            foreach ($listeners as $listener) {
                pmssIpv4TestRequire(preg_match('/\buid:'.$account['uid'].'\b/', $listener) !== 1, 'account has inet6 listener');
            }
            if ($entry['client'] === 'rtorrent') {
                $socket = $account['dir'].'/.rtorrent.socket';
                pmssIpv4TestRequire(rtorrentScgiCall($socket, 'system.pid', ['']) === $pid, 'SCGI process identity');
                pmssIpv4TestRequire(rtorrentScgiCall($socket, 'network.bind_address', ['']) === '0.0.0.0', 'IPv4 bind readback');
                $version = rtorrentScgiCall($socket, 'system.client_version', ['']);
                pmssIpv4TestRequire(is_string($version) && $version !== '', 'client version readback');
                if (version_compare($version, '0.16', '>=')) {
                    pmssIpv4TestRequire(in_array(rtorrentScgiCall($socket, 'network.block.ipv6', ['']), [1, true], true), 'IPv6 block readback');
                }
            }
            $trace = @file_get_contents($row['trace'] ?? '');
            $ipv4 = false;
            // strace prefixes worker-thread calls with TIDs, not the process leader's PID.
            foreach ((array) glob('/proc/'.$pid.'/task/[0-9]*', GLOB_ONLYDIR) as $task) {
                if (is_string($trace) && pmssIpv4RuntimeTraceIsIpv4($trace, (int) basename($task))) $ipv4 = true;
            }
            pmssIpv4TestRequire($ipv4, 'missing same-process IPv4 control or outbound IPv6 attempt');
            echo '[PASS] '.$id." account inet6=0; outbound trace IPv6=0\n";
        } catch (Throwable $error) {
            $failed++;
            echo '[FAIL] '.$id.' '.$error->getMessage()."\n";
        }
    }
    pmssIpv4TestRequire($failed === 0, 'runtime matrix has '.$failed.' failure(s)');
}

$options = getopt('', ['arg:', 'matrix:']);
try {
    if (($options['arg'] ?? '') === 'RtorrentIpv4ConfigTest') pmssRtorrentIpv4ConfigTest();
    elseif (($options['arg'] ?? '') === 'Ipv4RuntimeTraceTest') pmssIpv4RuntimeTraceTest();
    elseif (($options['arg'] ?? '') === 'Ipv4RuntimeTest') pmssIpv4RuntimeTest($options['matrix'] ?? '');
    else throw new RuntimeException('select --arg=RtorrentIpv4ConfigTest, --arg=Ipv4RuntimeTraceTest or --arg=Ipv4RuntimeTest --matrix=PATH');
    echo '[PASS] '.$options['arg']."\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] '.$error->getMessage()."\n");
    exit(1);
}
