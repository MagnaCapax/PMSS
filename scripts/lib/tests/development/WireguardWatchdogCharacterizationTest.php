<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/cron/checkWireguard.php';

/**
 * Lock scripts/cron/checkWireguard.php through synthetic observations.
 * Covers scripts/lib/wireguard/watchdog.php, scripts/lib/wireguard/watchdogCommands.php
 * and scripts/lib/wireguard/watchdogPeers.php without host probes.
 */
class WireguardWatchdogCharacterizationTest extends TestCase
{
    public function testMainKeepsConfigRefusalsQuietOnlyForMissingConfig(): void
    {
        $results = $this->observe(<<<'PHP'
$rows = [];
foreach (['missing', 'not_regular', 'unreadable'] as $status) {
    foreach ([false, true] as $debug) {
        resetFixture(['users' => ['status' => $status, 'users' => ['fixtur01']]]);
        ob_start();
        $rc = pmssWireguardCheckMain($debug ? ['checkWireguard.php', '--debug'] : ['checkWireguard.php']);
        $rows[] = [$rc, ob_get_clean(), $GLOBALS['fixture']['calls']];
    }
}
echo json_encode($rows);
PHP
        );
        $expected = [];
        foreach (['missing', 'not_regular', 'unreadable'] as $status) {
            foreach ([false, true] as $debug) {
                $message = $status === 'missing' ? 'wireguard config missing; skipping check'
                    : 'wireguard config '.$status.'; skipping check';
                $expected[] = [0, $status === 'missing' && !$debug ? '' : 'STAMP '.$message."\n", [['users', '/fixture/wg0.conf']]];
            }
        }
        $this->assertSame($expected, $results);
    }

    public function testMainPreservesModuleRepairAndThreeServiceStates(): void
    {
        $results = $this->observe(<<<'PHP'
$rows = [];
foreach ([true, false, null] as $active) {
    foreach ([false, true] as $debug) {
        foreach ([0, 7] as $showRc) {
            $responses = [['rc' => 2, 'stdout' => 'wireguard 1 0'], ['rc' => 3]];
            if ($active === false) $responses[] = ['rc' => 4];
            if ($active === null) {
                $responses[] = ['rc' => $showRc];
                if ($showRc !== 0) $responses[] = ['rc' => 5];
            }
            resetFixture(['active' => $active, 'responses' => $responses]);
            ob_start();
            $rc = pmssWireguardCheckMain($debug ? ['checkWireguard.php', '--debug'] : ['checkWireguard.php']);
            $rows[] = [$rc, ob_get_clean(), $GLOBALS['fixture']['calls']];
        }
    }
}
echo json_encode($rows);
PHP
        );
        $expected = [];
        foreach ([true, false, null] as $active) {
            foreach ([false, true] as $debug) {
                foreach ([0, 7] as $showRc) {
                    $output = "STAMP failed to load wireguard kernel module (rc=3)\n";
                    $calls = [['users', '/fixture/wg0.conf'], ['command', 'lsmod', 30],
                        ['command', "modprobe 'wireguard'", 30],
                        ['log', 'fixtur01', 'wireguard: failed to load kernel module (rc=3)'],
                        ['active', 'wg-quick@wg0']];
                    if ($active === true) {
                        if ($debug) $output .= "STAMP wg-quick@wg0 active\nSTAMP wg0 config has no peers to reconcile\n";
                        $calls[] = ['keys', '/fixture/wg0.conf'];
                    } elseif ($active === false) {
                        $output .= "STAMP wg-quick@wg0 inactive, attempting restart\nSTAMP failed to restart wg-quick@wg0 (rc=4)\n";
                        $calls[] = ['command', "systemctl 'restart' 'wg-quick@wg0'", 30];
                        $calls[] = ['log', 'fixtur01', 'wireguard: wg-quick@wg0 restart failed (rc=4)'];
                    } else {
                        $calls[] = ['command', "wg 'show'", 30];
                        if ($showRc !== 0) {
                            $output .= "STAMP wg0 interface missing; attempting wg-quick up\nSTAMP failed to bring up wg0 (rc=5)\n";
                            $calls[] = ['command', "wg-quick 'up' 'wg0'", 30];
                            $calls[] = ['log', 'fixtur01', 'wireguard: wg0 bring-up failed (rc=5)'];
                        } elseif ($debug) $output .= "STAMP wg show reports interface active\n";
                    }
                    $expected[] = [0, $output, $calls];
                }
            }
        }
        $this->assertSame($expected, $results);
    }

    public function testLoadedModuleAndSuccessfulActionsKeepTheirReports(): void
    {
        $results = $this->observe(<<<'PHP'
$rows = [];
foreach ([[true, true], [false, true], [null, true], [true, false]] as [$active, $loaded]) {
    $responses = [['rc' => 0, 'stdout' => $loaded ? 'wireguard 1 0' : 'wireguarded 1 0']];
    if (!$loaded) $responses[] = ['rc' => 0];
    if ($active === false) $responses[] = ['rc' => 0];
    if ($active === null) { $responses[] = ['rc' => 1]; $responses[] = ['rc' => 0]; }
    resetFixture(['active' => $active, 'responses' => $responses]);
    ob_start();
    $rc = pmssWireguardCheckMain(['checkWireguard.php']);
    $rows[] = [$rc, ob_get_clean(), $GLOBALS['fixture']['calls']];
}
echo json_encode($rows);
PHP
        );
        $base = [['users', '/fixture/wg0.conf'], ['command', 'lsmod', 30]];
        $this->assertSame([
            [0, '', array_merge($base, [['active', 'wg-quick@wg0'], ['keys', '/fixture/wg0.conf']])],
            [0, "STAMP wg-quick@wg0 inactive, attempting restart\nSTAMP wg-quick@wg0 restarted successfully\n", array_merge($base, [
                ['active', 'wg-quick@wg0'], ['command', "systemctl 'restart' 'wg-quick@wg0'", 30], ['log', 'fixtur01', 'wireguard: wg-quick@wg0 restarted']])],
            [0, "STAMP wg0 interface missing; attempting wg-quick up\nSTAMP wg0 brought up successfully\n", array_merge($base, [
                ['active', 'wg-quick@wg0'], ['command', "wg 'show'", 30], ['command', "wg-quick 'up' 'wg0'", 30], ['log', 'fixtur01', 'wireguard: wg0 brought up']])],
            [0, "STAMP loaded wireguard kernel module\n", array_merge($base, [
                ['command', "modprobe 'wireguard'", 30], ['log', 'fixtur01', 'wireguard: kernel module loaded'],
                ['active', 'wg-quick@wg0'], ['keys', '/fixture/wg0.conf']])],
        ], $results);
    }

    public function testPeerReconciliationKeepsReadFailureMatchSyncAndRestartOrder(): void
    {
        $results = $this->observe(<<<'PHP'
$key = base64_encode(str_repeat("\0", 32));
$rows = [];
foreach (['configFailure', 'runningFailure', 'match', 'sync', 'restart'] as $mode) {
    resetFixture(['keys' => ['status' => $mode === 'configFailure' ? 'unreadable' : 'ok', 'keys' => [$key]],
        'responses' => [['rc' => $mode === 'runningFailure' ? 6 : 0, 'stdout' => $mode === 'match' ? $key : ''],
            ['rc' => $mode === 'restart' ? 8 : 0, 'stdout' => 'stripped'], ['rc' => 0]]]);
    ob_start();
    pmssWireguardReconcileConfiguredPeers('/fixture/wg0.conf', ['fixtur01'], 'P ', true);
    $rows[] = [ob_get_clean(), $GLOBALS['fixture']['calls']];
}
echo json_encode($rows);
PHP
        );
        $read = [['keys', '/fixture/wg0.conf']];
        $running = array_merge($read, [['command', "wg 'show' 'wg0' 'peers'", 30]]);
        $attempt = "P wg0 missing 1 configured peer(s), attempting syncconf\n";
        $this->assertSame([
            ["P wg0 config has no peers to reconcile\n", $read],
            ["P failed to inspect running wg0 peers (rc=6)\n", $running],
            ["P wg0 runtime peers match config\n", $running],
            [$attempt."P wg0 peer set reconciled with syncconf\n", array_merge($running, [
                ['command', "wg-quick 'strip' 'wg0'", 30], ['temp', '/fixture/tmp', 'pmss-wg-sync-'],
                ['write', '/fixture/stage', 'stripped'], ['chmod', '/fixture/stage', 0600],
                ['command', "wg 'syncconf' 'wg0' '/fixture/stage'", 30], ['unlink', '/fixture/stage'],
                ['log', 'fixtur01', 'wireguard: wg0 peer set reconciled with syncconf']])],
            [$attempt."P wg peer reconcile failed during strip (rc=8), attempting restart\nP wg-quick@wg0 restarted successfully\n", array_merge($running, [
                ['command', "wg-quick 'strip' 'wg0'", 30], ['command', "systemctl 'restart' 'wg-quick@wg0'", 30],
                ['log', 'fixtur01', 'wireguard: wg-quick@wg0 restarted']])],
        ], $results);
    }

    public function testSyncconfRetainsTempfileGatesAndThrowableBoundary(): void
    {
        $results = $this->observe(<<<'PHP'
$rows = [];
foreach (['strip', 'empty', 'temp', 'false', 'short', 'mode', 'sync', 'throw'] as $mode) {
    resetFixture(['temp' => $mode === 'temp' ? false : '/fixture/stage',
        'write' => $mode === 'false' ? false : ($mode === 'short' ? 1 : 8), 'chmod' => $mode !== 'mode',
        'responses' => [['rc' => $mode === 'strip' ? 9 : 0, 'stdout' => $mode === 'empty' ? " \n" : 'stripped'],
            $mode === 'throw' ? new \RuntimeException('fixture failure') : ['rc' => 4]]]);
    try { $result = pmssWireguardSyncconfFromConfig(); }
    catch (\RuntimeException $error) { $result = $error->getMessage(); }
    $rows[] = [$result, $GLOBALS['fixture']['calls']];
}
echo json_encode($rows);
PHP
        );
        $strip = [['command', "wg-quick 'strip' 'wg0'", 30]];
        $temp = array_merge($strip, [['temp', '/fixture/tmp', 'pmss-wg-sync-']]);
        $expected = [[['stage' => 'strip', 'rc' => 9], $strip], [['stage' => 'strip', 'rc' => 0], $strip],
            [['stage' => 'tempfile', 'rc' => 1], $temp]];
        foreach (['false', 'short', 'mode', 'sync', 'throw'] as $mode) {
            $calls = array_merge($temp, [['write', '/fixture/stage', 'stripped'], ['chmod', '/fixture/stage', 0600]]);
            if ($mode === 'sync' || $mode === 'throw') $calls[] = ['command', "wg 'syncconf' 'wg0' '/fixture/stage'", 30];
            $calls[] = ['unlink', '/fixture/stage'];
            $expected[] = [$mode === 'throw' ? 'fixture failure' : ['stage' => $mode === 'sync' ? 'syncconf' : 'tempfile', 'rc' => $mode === 'sync' ? 4 : 1], $calls];
        }
        $this->assertSame($expected, $results);
    }

    public function testSyncconfCleansAcquiredTempfileThroughExceptionsAndErrors(): void
    {
        $results = $this->observe(<<<'PHP'
$rows = [];
foreach (['write', 'chmod', 'sync', 'unlink'] as $stage) {
    foreach ([\RuntimeException::class, \Error::class] as $type) {
        foreach ([false, true] as $cleanupThrows) {
            $fault = new $type('fixture failure');
            $cleanup = $cleanupThrows ? new \LogicException('cleanup failure') : true;
            resetFixture(['write' => $stage === 'write' ? $fault : 8,
                'chmod' => $stage === 'chmod' ? $fault : true,
                'unlink' => $stage === 'unlink' ? $fault : $cleanup,
                'responses' => [['rc' => 0, 'stdout' => 'stripped'], $stage === 'sync' ? $fault : ['rc' => 0]]]);
            try { pmssWireguardSyncconfFromConfig(); $sameFault = false; }
            catch (\Throwable $error) { $sameFault = $error === ($stage !== 'unlink' && $cleanupThrows ? $cleanup : $fault); }
            $rows[] = [$sameFault, $GLOBALS['fixture']['calls']];
        }
    }
}
// An unsuccessful removal retains the existing successful sync result.
resetFixture(['unlink' => false, 'responses' => [['rc' => 0, 'stdout' => 'stripped'], ['rc' => 0]]]);
$rows[] = [pmssWireguardSyncconfFromConfig(), $GLOBALS['fixture']['calls']];
echo json_encode($rows);
PHP
        );
        $base = [['command', "wg-quick 'strip' 'wg0'", 30], ['temp', '/fixture/tmp', 'pmss-wg-sync-'],
            ['write', '/fixture/stage', 'stripped']];
        $expected = [];
        foreach (['write', 'chmod', 'sync', 'unlink'] as $stage) {
            $calls = $base;
            if ($stage !== 'write') $calls[] = ['chmod', '/fixture/stage', 0600];
            if ($stage === 'sync' || $stage === 'unlink') $calls[] = ['command', "wg 'syncconf' 'wg0' '/fixture/stage'", 30];
            $calls[] = ['unlink', '/fixture/stage'];
            // Both throwable families, with successful cleanup and with a later cleanup fault.
            for ($i = 0; $i < 4; ++$i) $expected[] = [true, $calls];
        }
        $expected[] = [['stage' => 'syncconf', 'rc' => 0], $calls];
        $this->assertSame($expected, $results);
    }

    public function testMissingPeerSelectionPreservesDuplicatesAndListKeys(): void
    {
        $this->assertSame(['second', 'second', 'third'], \pmssWireguardMissingPeerPublicKeys(
            [8 => 'first', 2 => 'second', 17 => 'second', 4 => 'third'], ['first', 'first']));
        $this->assertSame([], \pmssWireguardMissingPeerPublicKeys([], ['first']));
        $this->assertSame(['0', 0, ''], \pmssWireguardMissingPeerPublicKeys(['0', 0, ''], []));
        $this->assertSame([''], \pmssWireguardMissingPeerPublicKeys(['0', 0, ''], [0]));
    }

    public function testCommandCaptureKeepsQuotingTimeoutsAndResultCoercion(): void
    {
        $this->assertSame([
            [['rc' => 1, 'stdout' => '', 'stderr' => ''], [['command', "fixture-tool 'two words' 'quote'\\''value'", 30]]],
            [['rc' => 7, 'stdout' => '0', 'stderr' => '1'], [['command', 'fixture-tool', 9]]],
        ], $this->observe(<<<'PHP'
$rows = [];
foreach ([[[], ['two words', "quote'value"], 30], [['rc' => '7', 'stdout' => 0, 'stderr' => true], [], 9]] as [$result, $args, $timeout]) {
    resetFixture(['responses' => [$result]]);
    $result = pmssWireguardCommandCapture('fixture-tool', $args, $timeout);
    $rows[] = [$result, $GLOBALS['fixture']['calls']];
}
echo json_encode($rows);
PHP
        ));
    }

    public function testRunningPeersKeepValidationDeduplicationAndFailureShape(): void
    {
        $first = base64_encode(str_repeat("\0", 32));
        $second = base64_encode(str_repeat("\1", 32));
        $this->assertSame([
            ['status' => 'ok', 'keys' => [$first, $second], 'rc' => 0],
            ['status' => 'unreadable', 'keys' => [], 'rc' => 6],
            ['status' => 'ok', 'keys' => [], 'rc' => 0],
        ], $this->observe(<<<'PHP'
$first = base64_encode(str_repeat("\0", 32));
$second = base64_encode(str_repeat("\1", 32));
$rows = [];
foreach ([[0, " \t".$second."\r\ninvalid\n".$first."\n".$second."\n"], [6, $first], [0, " \r\n"]] as [$rc, $stdout]) {
    resetFixture(['responses' => [['rc' => $rc, 'stdout' => $stdout]]]);
    $rows[] = pmssWireguardRunningPeerPublicKeys();
}
echo json_encode($rows);
PHP
        ));
    }

    /** Export the real watchdog definitions with synthetic command and filesystem boundaries. */
    private function observe(string $body): array
    {
        $script = <<<'PHP'
namespace WireguardWatchdogFixture;
function resetFixture(array $overrides = []) {
    $GLOBALS['fixture'] = $overrides + ['calls' => [], 'active' => true,
        'users' => ['status' => 'ok', 'users' => ['fixtur01']], 'keys' => ['status' => 'ok', 'keys' => []],
        'responses' => [], 'temp' => '/fixture/stage', 'write' => 8, 'chmod' => true];
}
function date($format) { return 'STAMP'; }
function pmssWireguardCheckConfigPath() { return '/fixture/wg0.conf'; }
function pmssWireguardPeerUsersFromConfig($path) { $GLOBALS['fixture']['calls'][] = ['users', $path]; return $GLOBALS['fixture']['users']; }
function pmssWireguardPeerPublicKeysFromConfig($path) { $GLOBALS['fixture']['calls'][] = ['keys', $path]; return $GLOBALS['fixture']['keys']; }
function pmssSystemdUnitIsActive($unit) { $GLOBALS['fixture']['calls'][] = ['active', $unit]; return $GLOBALS['fixture']['active']; }
function pmssUserLog($user, $message) { $GLOBALS['fixture']['calls'][] = ['log', $user, $message]; }
function pmssCommandCapture($command, $timeout) {
    $GLOBALS['fixture']['calls'][] = ['command', $command, $timeout];
    if ($GLOBALS['fixture']['responses'] === []) throw new \LogicException('unexpected command: '.$command);
    $result = array_shift($GLOBALS['fixture']['responses']);
    if ($result instanceof \Throwable) throw $result;
    return $result;
}
function sys_get_temp_dir() { return '/fixture/tmp'; }
function tempnam($dir, $prefix) { $GLOBALS['fixture']['calls'][] = ['temp', $dir, $prefix]; return $GLOBALS['fixture']['temp']; }
function file_put_contents($path, $bytes) {
    $GLOBALS['fixture']['calls'][] = ['write', $path, $bytes];
    if ($GLOBALS['fixture']['write'] instanceof \Throwable) throw $GLOBALS['fixture']['write'];
    return $GLOBALS['fixture']['write'];
}
function chmod($path, $mode) {
    $GLOBALS['fixture']['calls'][] = ['chmod', $path, $mode];
    if ($GLOBALS['fixture']['chmod'] instanceof \Throwable) throw $GLOBALS['fixture']['chmod'];
    return $GLOBALS['fixture']['chmod'];
}
function unlink($path) {
    $GLOBALS['fixture']['calls'][] = ['unlink', $path];
    $result = $GLOBALS['fixture']['unlink'] ?? true;
    if ($result instanceof \Throwable) throw $result;
    return $result;
}
PHP;
        $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/wireguard.php'), true).';';
        $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/cli/optionParser.php'), true).';';
        // Discover definitions by owning file so this same lock works before and after the split.
        foreach (get_defined_functions()['user'] as $name) {
            if (strpos(strtolower($name), 'pmsswireguard') !== 0) continue;
            $file = (new \ReflectionFunction($name))->getFileName();
            if (basename($file) === 'checkWireguard.php' || strpos($file, '/wireguard/watchdog') !== false) {
                $script .= \pmssTestingFunctionExport($name, 'WireguardWatchdogFixture');
            }
        }
        return $this->pmssRunInlinePhpJson($script.$body);
    }
}
