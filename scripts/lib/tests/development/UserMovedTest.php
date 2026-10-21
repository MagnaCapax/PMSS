<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/moved.php';

/** Exercise moved argument, persistence, predicate, and reversible lifecycle contracts. */
class UserMovedTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-moved-']; }

    private function store(): \UserConfigStore
    {
        $store = new \UserConfigStore($this->tempDir.'/config');
        $this->assertTrue($store->set('mvdtst', ['ramMiB' => 512, 'rtorrentPort' => 5000, 'quota' => 5, 'quotaBurst' => 6]));
        return $store;
    }

    public function testCanonicalDatesAndClearParse(): void
    {
        foreach (['2026-01-01', '2028-02-29', '2000-02-29', '9999-12-31', '2020-01-01'] as $date) {
            $this->assertSame(['mvdtst', $date], \pmssUserMovedParseArguments(['userMoved.php', ' MVDTST ', '--until='.$date]));
        }
        $this->assertSame(['mvdtst', null], \pmssUserMovedParseArguments(['userMoved.php', 'mvdtst', '--clear']));
    }

    public function testMalformedArgumentsFailBeforeOperations(): void
    {
        $cases = [[], ['mvdtst'], ['bad;id', '--clear'], ['../mvdtst', '--clear'], ['mvdtst', '--clear', '--until=2026-01-01'],
            ['mvdtst', '--other'], ['mvdtst', '--until=2026-02-30'], ['mvdtst', '--until=2026-1-1'], ['mvdtst', '--until=1900-02-29'],
            ['mvdtst', '--until=0000-01-01'], ['mvdtst', '--until=2026-00-01'], ['mvdtst', '--until=2026-12-32'],
            ['mvdtst', "--until=2026-01-01\n"], ['mvdtst', '--until=2026-01-01;id'], ['mvdtst', '--until=$(id)']];
        foreach ($cases as $args) {
            $rejected = false;
            try { \pmssUserMovedParseArguments(array_merge(['userMoved.php'], $args)); }
            catch (\InvalidArgumentException $error) { $rejected = true; }
            $this->assertTrue($rejected, json_encode($args));
            // Invoke the real CLI too: errors must have a failing exit status.
            $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->pmssRepoPath('scripts/userMoved.php'));
            foreach ($args as $arg) $command .= ' '.escapeshellarg($arg);
            $result = $this->pmssExecShellCommand($command.' 2>&1');
            $this->assertTrue($result['rc'] !== 0);
            $this->assertTrue(trim($result['output']) !== '');
        }
    }

    public function testMovedStoreRoundTripsUpdatesAndClearsWithoutCache(): void
    {
        $store = $this->store();
        $this->assertFalse($store->setMovedUntil('missing', '2026-11-01'));
        foreach (['2026-11-01', '2026-12-01'] as $date) {
            $this->assertTrue($store->setMovedUntil('mvdtst', $date));
            $this->assertSame($date, \pmssUserMovedUntil('mvdtst', $store));
            $payload = $store->get('mvdtst');
            $payload['quota'] = 10;
            $this->assertTrue($store->set('mvdtst', $payload));
            $this->assertSame($date, $store->get('mvdtst')['movedUntil']);
        }
        $path = $this->tempDir.'/config/users/mvdtst.json';
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertFalse($store->setMovedUntil('mvdtst', '2026-02-30'));
        $this->assertTrue($store->setMovedUntil('mvdtst', null));
        $this->assertFalse(array_key_exists('movedUntil', $store->get('mvdtst')));
        foreach (['2026-02-30', '2026-1-1', "2026-01-01\n", ['2026-01-01'], true] as $invalid) {
            $payload = $store->get('mvdtst');
            $payload['movedUntil'] = $invalid;
            $this->assertTrue($store->set('mvdtst', $payload));
            $this->assertSame(null, \pmssUserMovedUntil('mvdtst', $store));
        }
        // setMovedUntil must use set(), since persist() also writes a home cache.
        $source = file_get_contents($this->pmssRepoPath('scripts/lib/user/userConfigStore.php'));
        $method = substr($source, strpos($source, 'public function setMovedUntil'), strpos($source, 'public function persist') - strpos($source, 'public function setMovedUntil'));
        $this->assertFalse(strpos($method, '->persist(') !== false);
    }

    public function testSharedPredicateUsesStoreAndLeavesWebRootPredicateUnchanged(): void
    {
        $store = $this->store();
        $this->pmssWriteFile($this->tempDir.'/home/mvdtst/www/index.php', 'keep');
        $script = 'namespace MovedPredicateFixture; function pmssUserMovedUntil($user) { return \\pmssUserMovedUntil($user, $GLOBALS["store"]); }';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/watchdog.php', 'MovedPredicateFixture');
        $script .= '$GLOBALS["store"] = new \\UserConfigStore('.var_export($this->tempDir.'/config', true).');';
        $script .= '$root = '.var_export($this->tempDir.'/home', true).'; $out = [];';
        $script .= '$out[] = pmssUserServicesParked("mvdtst", $root); $GLOBALS["store"]->setMovedUntil("mvdtst", "2020-01-01");';
        $script .= '$out[] = pmssUserServicesParked("mvdtst", $root); $out[] = pmssUserWebRootUnavailable("mvdtst", $root);';
        $script .= '$GLOBALS["store"]->setMovedUntil("mvdtst", null); $out[] = pmssUserServicesParked("mvdtst", $root);';
        $script .= 'mkdir($root."/mvdtst/www-disabled"); $out[] = pmssUserServicesParked("mvdtst", $root);';
        $script .= '$out[] = pmssUserServicesParked("missing", $root); echo json_encode($out);';
        $this->assertSame([false, true, false, false, true, true], $this->pmssRunInlinePhpJson($script));
    }

    public function testLifecyclePersistsBeforeStopAndClearDoesNotStartAnything(): void
    {
        $this->store();
        // Namespace injection keeps lifecycle audit logging and all shell work hermetic.
        $script = 'namespace MovedLifecycleFixture; require_once '.var_export($this->pmssRepoPath('scripts/lib/user/moved.php'), true).';';
        $script .= 'class_alias("UserConfigStore", __NAMESPACE__."\\\\UserConfigStore"); class_alias("RuntimeException", __NAMESPACE__."\\\\RuntimeException");';
        $script .= 'function pmssUserLifecycleContextLogHomeInfo(...$args) {} function pmssUserLifecycleContextLogStatusMessage(...$args) {}';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/moved.php', 'MovedLifecycleFixture');
        $script .= '$store = new UserConfigStore('.var_export($this->tempDir.'/config', true).'); $events = [];';
        $script .= '$stop = function($user) use ($store, &$events) { $events[] = $store->get($user)["movedUntil"]; return 0; };';
        $script .= '$refresh = function() use (&$events) { $events[] = "refresh"; return 0; }; $out = [];';
        $script .= 'foreach (["2026-11-01", "2026-12-01", null, null] as $date) $out[] = pmssUserMovedApply("mvdtst", $date, $store, $stop, $refresh);';
        $script .= 'echo json_encode([$out, $events, $store->get("mvdtst")]);';
        [$codes, $events, $payload] = $this->pmssRunInlinePhpJson($script, [], '2>&1');
        $this->assertSame([0, 0, 0, 0], $codes);
        $this->assertSame(['2026-11-01', 'refresh', '2026-12-01', 'refresh', 'refresh'], $events);
        $this->assertFalse(isset($payload['movedUntil']));
    }

    public function testStopsManagedAppsWithoutSignallingSshOrWritingHomeMarkers(): void
    {
        $script = <<<'PHP'
namespace MovedStopFixture;
function rtorrentProcessKillManagedPids($user, $signal) { $GLOBALS['events'][] = ['rtorrent', $user, $signal]; }
function pmssUserWatchdogTerminateProcesses($user, $names) { $GLOBALS['events'][] = ['processes', $names]; }
function pmssToggleAppCatalogRead() { return [['watchdogProcess' => 'slskd']]; }
function pmssMediaStackWatchdogAppDefinitions() { return ['sonarr' => []]; }
function pmssMediaStackWatchdogSessionRunning($user, $app) { return true; }
function pmssBuildUserShellCommand($user, $command) { return 'account:'.$user.' '.$command; }
function pmssUserLifecycleStep($action, $user, $step, $command, $dryRun) {
    $GLOBALS['events'][] = [$step, $command]; return 0;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/moved.php', 'MovedStopFixture');
        $script .= '$GLOBALS["events"] = []; $rc = pmssUserMovedStopServices("mvdtst"); echo json_encode([$rc, $GLOBALS["events"]]);';
        [$rc, $events] = $this->pmssRunInlinePhpJson($script, [], '2>&1');
        $this->assertSame(0, $rc);
        $this->assertSame(['rtorrent', 'mvdtst', 9], $events[0]);
        $this->assertSame(['lighttpd', 'php-cgi', 'deluged', 'deluge-web', 'qbittorrent-nox', 'rclone', 'transmission-daemon', 'slskd'], $events[1][1]);
        $this->assertSame(['stop_sonarr', "account:mvdtst tmux kill-session -t '=sonarr'"], $events[2]);
        $this->assertSame(['stop_docker', "php '/scripts/util/userDocker.php' 'mvdtst' 'stop'"], $events[3]);
    }
}
