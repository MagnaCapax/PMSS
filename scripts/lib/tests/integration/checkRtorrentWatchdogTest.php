#!/usr/bin/env php
<?php
/**
 * Integration test: checkRtorrent SCGI watchdog end-to-end verification.
 *
 * Creates a temporary user, loads torrents, simulates an unresponsive SCGI
 * socket, and verifies the watchdog detects the condition, waits the grace
 * period, and restarts rTorrent.
 *
 * DESTRUCTIVE: creates and destroys a system user. Must run as root on a
 * live PMSS server. Not suitable for CI — run manually during verification.
 *
 * Usage:
 *   php scripts/lib/tests/integration/checkRtorrentWatchdogTest.php [--skip-torrents]
 *
 * Options:
 *   --skip-torrents  Skip torrent loading (faster, still tests watchdog)
 *
 * @author    Väinämöinen <noreply@pulsedmedia.com>
 * @copyright 2010-2025 Magna Capax Finland Oy
 * @license   Proprietary
 */

// --- Guards ---

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Must run as root\n");
    exit(1);
}

if (!is_dir('/etc/seedbox')) {
    fwrite(STDERR, "Not a PMSS server (no /etc/seedbox)\n");
    exit(1);
}

require_once dirname(__DIR__, 2).'/rtorrent/scgi.php';
require_once dirname(__DIR__, 2).'/rtorrent/process.php';

$args = $argv ?? [];
$skipTorrents = in_array('--skip-torrents', $args, true);

// --- Configuration ---

$testUser    = 'vtestwdg';
$testPass    = bin2hex(random_bytes(12));
$testQuotaMb = 256;
$testDiskGb  = 10;
$gracePeriod = 120;  // seconds — must match PMSS_RTORRENT_UNRESPONSIVE_GRACE
$torrentDir  = sys_get_temp_dir().'/pmss-watchdog-test-torrents';
$stateDir    = '/run/pmss';

// Torrent sources — well-known Linux ISO torrents.
$torrentUrls = [
    'https://releases.ubuntu.com/22.04/ubuntu-22.04.5-desktop-amd64.iso.torrent',
    'https://releases.ubuntu.com/22.04/ubuntu-22.04.5-live-server-amd64.iso.torrent',
    'https://torrent.fedoraproject.org/torrents/Fedora-Workstation-Live-x86_64-41.torrent',
    'https://torrent.fedoraproject.org/torrents/Fedora-Server-dvd-x86_64-41.torrent',
    'https://linuxmint.com/torrents/linuxmint-22-cinnamon-64bit.iso.torrent',
    'https://linuxmint.com/torrents/linuxmint-22-mate-64bit.iso.torrent',
    'https://linuxmint.com/torrents/linuxmint-22-xfce-64bit.iso.torrent',
];

// --- Helpers ---

$pass = 0;
$fail = 0;
$startTime = microtime(true);

function emit(string $status, string $msg): void
{
    echo "[{$status}] {$msg}\n";
}

function check(string $label, bool $condition): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        emit('PASS', $label);
    } else {
        $fail++;
        emit('FAIL', $label);
    }
}

/**
 * Send an XMLRPC call with optional params to a Unix socket.
 */
function scgiXmlrpcCall(string $socket, string $method, string $xmlParams = '', int $timeout = 15): ?string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<methodCall><methodName>' . htmlspecialchars($method, ENT_XML1, 'UTF-8') . '</methodName>'
        . '<params>' . $xmlParams . '</params></methodCall>';
    $resp = rtorrentScgiSend($socket, rtorrentScgiFormatRequest($xml), $timeout);
    if ($resp === false || strpos($resp, '<value>') === false) {
        return null;
    }
    if (strpos($resp, '<fault>') !== false) {
        return null;
    }
    return $resp;
}

/**
 * Clean up test artifacts unconditionally.
 */
function watchdogTestCleanup(string $user, string $torrentDir): void
{
    emit('INFO', 'Cleaning up...');

    // Terminate user.
    $rc = 0;
    @exec('/scripts/terminateUser.php --confirm '.escapeshellarg($user).' 2>&1', $out, $rc);

    // Handle immutable files.
    @exec('chattr -R -i '.escapeshellarg('/home/'.$user).' 2>/dev/null');
    @exec('rm -rf '.escapeshellarg('/home/'.$user).' 2>/dev/null');

    // Kill orphan processes.
    @exec('killall -9 -u '.escapeshellarg($user).' 2>/dev/null');

    // Remove torrent temp dir.
    @exec('rm -rf '.escapeshellarg($torrentDir).' 2>/dev/null');

    // Remove stale state files.
    @unlink('/run/pmss/checkRtorrent-unresponsive-'.$user.'.ts');
    @unlink('/run/pmss/checkRtorrent-missing-'.$user.'.ts');
    @unlink('/run/pmss/checkRtorrent-executor-present-rtorrent-missing-'.$user.'.ts');
}

// Register cleanup on exit (covers crashes, Ctrl-C via pcntl if available).
register_shutdown_function(function () use ($testUser, $torrentDir) {
    watchdogTestCleanup($testUser, $torrentDir);
});

// --- Pre-flight ---

emit('INFO', "Test user: {$testUser}");
emit('INFO', 'PMSS version: '.trim((string) @file_get_contents('/etc/seedbox/config/version')));

// Ensure test user doesn't already exist.
$existing = [];
@exec('id '.escapeshellarg($testUser).' 2>&1', $existing, $idRc);
if ($idRc === 0) {
    emit('WARN', "User {$testUser} already exists; cleaning up first");
    watchdogTestCleanup($testUser, $torrentDir);
    sleep(2);
}

// Record baseline process count.
$baselineOut = [];
@exec('pgrep -a rtorrent | wc -l', $baselineOut);
$baselineCount = (int) trim($baselineOut[0] ?? '0');
emit('INFO', "Baseline rtorrent count: {$baselineCount}");

// ==========================================
// TEST 1: Create user, verify SCGI responds
// ==========================================

emit('INFO', '--- Phase 1: User creation and SCGI baseline ---');

$addOut = [];
$addRc = 0;
@exec(
    'php /scripts/addUser.php '
    . escapeshellarg($testUser) . ' '
    . escapeshellarg($testPass) . ' '
    . (int) $testQuotaMb . ' '
    . (int) $testDiskGb
    . ' 2>&1',
    $addOut,
    $addRc
);

check('addUser exits 0', $addRc === 0);

// Wait for rtorrent to fully start.
$socket = rtorrentScgiSocketPath($testUser);
$rtReady = false;
for ($i = 0; $i < 15; $i++) {
    if (rtorrentScgiPing($socket, 3)) {
        $rtReady = true;
        break;
    }
    sleep(2);
}
check('rtorrent starts and SCGI responds', $rtReady);

if (!$rtReady) {
    emit('ABORT', 'rtorrent never started; aborting test');
    exit(1);
}

// Record PID.
$pidOut = [];
@exec('pgrep -u '.escapeshellarg($testUser).' rtorrent', $pidOut);
$originalPid = (int) trim($pidOut[0] ?? '0');
check('rtorrent PID obtained', $originalPid > 0);
emit('INFO', "Original PID: {$originalPid}");

// ==========================================
// TEST 2: Load torrents and measure baseline
// ==========================================

if (!$skipTorrents) {
    emit('INFO', '--- Phase 2: Torrent loading ---');

    @mkdir($torrentDir, 0755, true);
    $loaded = 0;

    foreach ($torrentUrls as $url) {
        $filename = $torrentDir.'/'.basename($url);
        @exec('wget -q --timeout=10 -O '.escapeshellarg($filename).' '.escapeshellarg($url).' 2>/dev/null', $wgetOut, $wgetRc);
        if ($wgetRc !== 0 || !is_file($filename) || filesize($filename) < 100) {
            continue;
        }

        $data = file_get_contents($filename);
        $params = '<param><value><string></string></value></param>'
            . '<param><value><base64>' . base64_encode($data) . '</base64></value></param>';
        if (scgiXmlrpcCall($socket, 'load.raw_start', $params)) {
            $loaded++;
        }
        usleep(200000);
    }

    // Set throttle.
    scgiXmlrpcCall($socket, 'throttle.global_down.max_rate.set',
        '<param><value><string></string></value></param><param><value><i4>10240</i4></value></param>');
    scgiXmlrpcCall($socket, 'throttle.global_up.max_rate.set',
        '<param><value><string></string></value></param><param><value><i4>10240</i4></value></param>');

    emit('INFO', "Loaded {$loaded} torrents, throttle set to 10KB/s");
    check('At least 1 torrent loaded', $loaded > 0);
}

// Baseline SCGI latency.
$t = microtime(true);
$pingOk = rtorrentScgiPing($socket, 5);
$pingMs = round((microtime(true) - $t) * 1000, 1);
check("SCGI baseline ping ({$pingMs}ms)", $pingOk);

// ==========================================
// TEST 3: Simulate SCGI stall via socket rename
// ==========================================

emit('INFO', '--- Phase 3: SCGI stall simulation ---');

$socketBak = $socket.'.bak';

// Remove any leftover backup.
@unlink($socketBak);

// Rename socket — SCGI immediately unreachable.
$renamed = @rename($socket, $socketBak);
check('Socket renamed successfully', $renamed);

$stallStart = time();
emit('INFO', 'Socket renamed at '.date('c'));

// Verify stall.
$stalled = !rtorrentScgiPing($socket, 2);
check('SCGI is now unresponsive', $stalled);

// Verify process still alive.
$stillAlive = false;
if (function_exists('posix_kill')) {
    $stillAlive = @posix_kill($originalPid, 0);
} else {
    @exec('kill -0 '.(int) $originalPid.' 2>/dev/null', $killOut, $killRc);
    $stillAlive = ($killRc === 0);
}
check('rtorrent process still alive after rename', $stillAlive);

// ==========================================
// TEST 4: Watchdog detection (first run)
// ==========================================

emit('INFO', '--- Phase 4: Watchdog detection ---');

// Clear any pre-existing state.
$unresponsiveState = $stateDir.'/checkRtorrent-unresponsive-'.$testUser.'.ts';
@unlink($unresponsiveState);

// Run watchdog — should detect and create state file.
$wdOut = [];
$wdRc = 0;
@exec('php /scripts/cron/checkRtorrent.php --debug 2>&1', $wdOut, $wdRc);

$stateCreated = is_file($unresponsiveState);
check('State file created after first detection', $stateCreated);

$wdOutput = implode("\n", $wdOut);
$observing = strpos($wdOutput, 'observing') !== false
    || strpos($wdOutput, 'unresponsive') !== false;
check('Watchdog logged "observing" or "unresponsive"', $observing);

// ==========================================
// TEST 5: Grace period then restart
// ==========================================

emit('INFO', '--- Phase 5: Grace period and restart ---');

$waitTime = $gracePeriod + 15;  // 135s to be safe
emit('INFO', "Waiting {$waitTime}s for grace period to expire...");
sleep($waitTime);

// Run watchdog again — should trigger restart.
$wdOut2 = [];
$wdRc2 = 0;
@exec('php /scripts/cron/checkRtorrent.php --debug 2>&1', $wdOut2, $wdRc2);

$wdOutput2 = implode("\n", $wdOut2);
$restartTriggered = strpos($wdOutput2, 'restarting') !== false
    || strpos($wdOutput2, 'startRtorrent') !== false;
check('Watchdog triggered restart after grace period', $restartTriggered);

// State file should be cleared.
$stateCleared = !is_file($unresponsiveState);
check('State file cleared after restart', $stateCleared);

// Wait for new rtorrent to start.
sleep(5);

// ==========================================
// TEST 6: Verify recovery
// ==========================================

emit('INFO', '--- Phase 6: Recovery verification ---');

// Old PID should be dead.
$oldDead = false;
if (function_exists('posix_kill')) {
    $oldDead = !@posix_kill($originalPid, 0);
} else {
    @exec('kill -0 '.(int) $originalPid.' 2>/dev/null', $kOut, $kRc);
    $oldDead = ($kRc !== 0);
}
check('Old rtorrent PID killed', $oldDead);

// New PID should exist.
$newPidOut = [];
@exec('pgrep -u '.escapeshellarg($testUser).' rtorrent', $newPidOut);
$newPid = (int) trim($newPidOut[0] ?? '0');
check('New rtorrent PID exists', $newPid > 0);
check('New PID differs from original', $newPid !== $originalPid);
emit('INFO', "New PID: {$newPid}");

// New socket should exist.
$socketReady = false;
for ($i = 0; $i < 15; $i++) {
    if (file_exists($socket)) {
        $socketReady = true;
        break;
    }
    sleep(2);
}
check('New socket created', $socketReady);

// SCGI should respond.
$recovered = false;
for ($i = 0; $i < 10; $i++) {
    if (rtorrentScgiPing($socket, 5)) {
        $recovered = true;
        break;
    }
    sleep(2);
}
check('SCGI responsive after recovery', $recovered);

// Other users unaffected.
$postOut = [];
@exec('pgrep -a rtorrent | wc -l', $postOut);
$postCount = (int) trim($postOut[0] ?? '0');
// Allow ±1 for timing (test user restarting).
$othersOk = abs($postCount - $baselineCount) <= 1;
check("Other users unaffected (pre={$baselineCount}, post={$postCount})", $othersOk);

// ==========================================
// Summary
// ==========================================

$elapsed = round(microtime(true) - $startTime, 1);
echo "\n";
echo str_repeat('=', 50)."\n";
echo "Results: {$pass} passed, {$fail} failed ({$elapsed}s)\n";
echo str_repeat('=', 50)."\n";

// Cleanup happens via shutdown function.
exit($fail === 0 ? 0 : 1);
