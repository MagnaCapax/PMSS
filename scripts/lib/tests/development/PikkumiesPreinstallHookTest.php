<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Existing-home Pikkumies preinstall (ADR 0090): space/RAM floors, installer present, once per account. */
final class PikkumiesPreinstallHookTest extends TestCase
{
    public function testLifecycleMarkerClearAcceptsOnlyFilesAndLinks(): void
    {
        require_once dirname(__DIR__, 2).'/user/pikkumiesPreinstallState.php';
        $root = $this->pmssMakeTempDir('pmss-pk-clear-');
        $state = $root.'/state';
        $this->pmssWriteFile($state.'/fixt01', 'done');
        $this->assertTrue(\pmssPikkumiesPreinstallStateClear('fixt01', $state));
        $this->assertFalse(file_exists($state.'/fixt01'));
        $this->assertTrue(\pmssPikkumiesPreinstallStateClear('fixt01', $state));

        $this->pmssWriteFile($root.'/target', 'keep');
        symlink($root.'/target', $state.'/fixt01');
        $this->assertTrue(\pmssPikkumiesPreinstallStateClear('fixt01', $state));
        $this->assertFalse(is_link($state.'/fixt01'));
        $this->assertSame('keep', file_get_contents($root.'/target'));

        $this->pmssEnsureDir($state.'/fixt01');
        $this->assertFalse(\pmssPikkumiesPreinstallStateClear('fixt01', $state));
        $this->assertTrue(is_dir($state.'/fixt01'));
        $this->assertFalse(\pmssPikkumiesPreinstallStateClear('../target', $state));
        $this->assertSame('keep', file_get_contents($root.'/target'));

        symlink($state, $root.'/state-link');
        $this->assertFalse(\pmssPikkumiesPreinstallStateClear('fixt01', $root.'/state-link'));
    }

    public function testAskStateClearRemovesOnlyThatAccountsFilesAndNoLinkTarget(): void
    {
        require_once dirname(__DIR__, 2).'/user/pikkumiesPreinstallState.php';
        $root = $this->pmssMakeTempDir('pmss-pk-ask-');
        $dir = $root.'/ask';
        foreach (['.out', '.in', '.lock', '.out.rc'] as $suffix) {
            $this->pmssWriteFile($dir.'/fixt01'.$suffix, 'x');
        }
        $this->pmssWriteFile($dir.'/fixt01ab.out', 'other account');
        $this->pmssWriteFile($root.'/target', 'keep');
        unlink($dir.'/fixt01.in');
        symlink($root.'/target', $dir.'/fixt01.in');

        $this->assertTrue(\pmssPikkumiesAskStateClear('fixt01', $dir));
        foreach (['.out', '.in', '.lock', '.out.rc'] as $suffix) {
            $this->assertFalse(file_exists($dir.'/fixt01'.$suffix) || is_link($dir.'/fixt01'.$suffix));
        }
        $this->assertSame('other account', file_get_contents($dir.'/fixt01ab.out'));
        $this->assertSame('keep', file_get_contents($root.'/target'));
        $this->assertTrue(\pmssPikkumiesAskStateClear('fixt01', $dir));
        $this->assertTrue(\pmssPikkumiesAskStateClear('fixt01', $root.'/missing'));

        $this->pmssEnsureDir($dir.'/fixt01.out');
        $this->assertFalse(\pmssPikkumiesAskStateClear('fixt01', $dir));
        $this->assertFalse(\pmssPikkumiesAskStateClear('../target', $dir));
        symlink($dir, $root.'/ask-link');
        $this->assertFalse(\pmssPikkumiesAskStateClear('fixt01', $root.'/ask-link'));
    }

    public function testStateHelpersRejectUnsafeDirectoryChainsWithoutChangingFiles(): void
    {
        require_once dirname(__DIR__, 2).'/user/pikkumiesPreinstallState.php';
        $root = $this->pmssMakeTempDir('pmss-pk-state-path-');
        $state = $root.'/target/state';
        $names = ['fixt01', 'fixt01.out', 'fixt01.in', 'fixt01.lock', 'fixt01.out.rc'];
        foreach ($names as $name) $this->pmssWriteFile($state.'/'.$name, 'keep');
        symlink($root.'/target', $root.'/parent-link');
        symlink($state, $root.'/state-link');
        $this->pmssWriteFile($root.'/file', 'keep');

        // Include a linked leaf with a trailing slash: is_link alone misses it.
        foreach (['', '/', 'relative-state', $root.'/target/../target/state',
            "\0".$state, $state."\0", $root.'/parent-link/state',
            $root.'/parent-link/new-state', $root.'/state-link/', $root.'/file'] as $unsafe) {
            $this->assertFalse(\pmssPikkumiesPreinstallStateClear('fixt01', $unsafe));
            $this->assertFalse(\pmssPikkumiesAskStateClear('fixt01', $unsafe));
            $this->assertSame(null, \pmssPikkumiesPreinstallStateMarkerPath('fixt02', $unsafe));
        }
        foreach ($names as $name) $this->assertSame('keep', file_get_contents($state.'/'.$name));
        $this->assertSame('keep', file_get_contents($root.'/file'));
        $this->assertFalse(file_exists($state.'/fixt02'));
        $this->assertFalse(file_exists($root.'/target/new-state'));
    }

    public function testUpdaterRejectsLinkedAncestorsBeforeStateCreationOrUserScan(): void
    {
        require_once dirname(__DIR__, 2).'/update/apps/pikkumiesPreinstall.php';
        $root = $this->pmssMakeTempDir('pmss-pk-hook-path-');
        $this->pmssWriteFile($root.'/target/state/fixt01', 'keep');
        symlink($root.'/target', $root.'/parent-link');
        $calls = [];
        $ops = self::pmssMakeCallbacks(['users' => ['fixt01'], 'run' => null], $calls);
        foreach (['state', 'new-state'] as $leaf) {
            \pmssPikkumiesPreinstallExistingRun($root.'/parent-link/'.$leaf, $ops);
        }
        $this->assertSame([], $calls);
        $this->assertSame('keep', file_get_contents($root.'/target/state/fixt01'));
        $this->assertFalse(file_exists($root.'/target/new-state'));
    }

    public function testStateHelpersPreserveMissingAndTrailingSlashDirectoryBehavior(): void
    {
        require_once dirname(__DIR__, 2).'/user/pikkumiesPreinstallState.php';
        $root = $this->pmssMakeTempDir('pmss-pk-state-valid-');
        $state = $root.'/new/parent/state/';
        $this->assertTrue(\pmssPikkumiesPreinstallStateClear('fixt01', $state));
        $this->assertTrue(\pmssPikkumiesAskStateClear('fixt01', $state));
        $this->assertSame($state.'/fixt01', \pmssPikkumiesPreinstallStateMarkerPath('fixt01', $state));
        $this->pmssWriteFile($state.'/fixt01', 'done');
        $this->pmssWriteFile($state.'/fixt01.out', 'output');
        $this->assertTrue(\pmssPikkumiesPreinstallStateClear('fixt01', $state));
        $this->assertTrue(\pmssPikkumiesAskStateClear('fixt01', $state));
        $this->assertSame([], glob($state.'*'));
    }

    public function testLifecycleFlowsClearMarkerAfterAccountAndSkeletonChanges(): void
    {
        $terminate = $this->pmssReadRepoExecutableSource('scripts/terminateUser.php');
        $this->assertFalse((bool) preg_match('/if\s*\(\s*false\s*\)/', $terminate), 'marker cleanup must be reachable');
        $this->assertOrderedStrings([
            'pmssTerminateUserFinalRemovalSteps($username, $uid, $killUserCommand)',
            'pmssTerminateUserClearPreinstallState($username, $dryRun, pmssUserAccountLookup($username) !== null)',
            'pmssTerminateUserCompletion($username, $purgePaths, $purgeResults, $dryRun)',
        ], $terminate);
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/recreateUser.php', [
            "pmssRunOrExit('cp -Rp /etc/skel ' . escapeshellarg(\$homeDir));",
            'pmssPikkumiesPreinstallStateClear($userName)',
        ]);
        $this->pmssAssertRepoFileContract('scripts/terminateUser.php', ['ordered' => [[
            'pmssTerminateUserFinalRemovalSteps($username, $uid, $killUserCommand)',
            'pmssTerminateUserClearPreinstallState($username, $dryRun, pmssUserAccountLookup($username) !== null)',
        ]], 'required' => ['pmssTerminateUserClearPreinstallState']]);
    }

    public function testWithoutHostMarkerStartsNewAndFinishesStoppedInstalls(): void
    {
        $this->assertTrue(include_once dirname(__DIR__, 2).'/update/apps/pikkumiesPreinstall.php');
        $root = $this->pmssMakeTempDir('pmss-pk-unmarked-');
        // fxfresh: no clone; fxfin: a finished install; fxstop: a clone whose install
        // stopped; fxmark: the same, marked as installed by the earlier hook; fxdone: re-run once already
        $users = ['fxfresh', 'fxfin', 'fxstop', 'fxmark', 'fxdone'];
        foreach ($users as $user) {
            $this->pmssWriteFile($root.'/home/'.$user.'/bin/install-pikkumies.sh', "#!/usr/bin/env bash\n");
            if ($user !== 'fxfresh') $this->pmssEnsureDir($root.'/home/'.$user.'/.mcxPikkumies/pikkumies/local');
        }
        $this->pmssWriteFile($root.'/home/fxfin/.mcxPikkumies/pikkumies/local/hermes-managed/config.yaml', "model: x\n");
        $this->pmssWriteFile($root.'/state/fxmark', '');
        $this->pmssWriteFile($root.'/state/fxdone', 'finished');
        $this->pmssWriteExecutableFile($root.'/stub/id', "#!/bin/sh\necho 4321\n");
        $runs = [];
        $ops = [
            'users' => static function () use (&$users): array { return $users; },
            'run' => $this->pmssMakeArrayLogger($runs),
            'homeBase' => $root.'/home',
        ] + self::pmssMakeCallbacks(['ramMiB' => 4096, 'freeSpace' => 106 * 1073741824]);
        $this->pmssWithPathPrefix($root.'/stub', function () use ($root, &$users, $ops, &$marked): void {
            \pmssPikkumiesPreinstallExistingRun($root.'/state', $ops);
            $marked = array_map('basename', glob($root.'/state/*'));
            $users = ['fxfin'];
            \pmssPikkumiesPreinstallExistingRun($root.'/state', $ops);
        });

        sort($marked);
        $this->assertSame(['fxdone', 'fxfin', 'fxmark'], $marked, 'a finished install is marked; a stopped one stays unmarked until it succeeds');
        $this->assertSame(1, count($runs), 'without a host marker new and stopped installs start; a finished install never reruns: '.implode(' | ', $runs));
        $this->assertSame(3, substr_count($runs[0], 'install-pikkumies.sh'), 'the new install and both stopped installs run: '.$runs[0]);
        $this->assertSame(2, substr_count($runs[0], 'grep -qF'), 'each skipped as the user when its crontab has the update line: '.$runs[0]);
        foreach (['fxstop', 'fxmark'] as $user) {
            $this->assertTrue(strpos($runs[0], ") && printf finished > '\\''".$root."/state/".$user."'\\''") !== false, "$user is recorded as finished only after it succeeds: ".$runs[0]);
        }
        $this->assertSame(1, substr_count($runs[0], ' && touch -- '), 'only the new clone gets an empty completion file: '.$runs[0]);
        $this->assertFalse(file_exists($root.'/home/fxfresh/.mcxPikkumies'));
    }

    public function testLinkedStateDirectoryAndMarkerAreNotWritten(): void
    {
        require_once dirname(__DIR__, 2).'/update/apps/pikkumiesPreinstall.php';
        $root = $this->pmssMakeTempDir('pmss-pk-links-');
        $this->pmssEnsureDir($root.'/target');
        symlink($root.'/target', $root.'/state-link');
        $calls = [];
        $ops = self::pmssMakeCallbacks(['users' => ['fixt01'], 'run' => null], $calls);
        \pmssPikkumiesPreinstallExistingRun($root.'/state-link', $ops);
        $this->assertSame([], $calls);
        $this->assertSame([], glob($root.'/target/*'));

        $this->pmssEnsureDir($root.'/state');
        symlink($root.'/absent', $root.'/state/fixt01');
        \pmssPikkumiesPreinstallExistingRun($root.'/state', $ops);
        $this->assertSame(['users'], $calls);
        $this->assertFalse(file_exists($root.'/absent'));
    }

    public function testStartsOnlyEligibleAccountsOnceInOneBackgroundChain(): void
    {
        require_once dirname(__DIR__, 2).'/update/apps/pikkumiesPreinstall.php';
        $root = $this->pmssMakeTempDir('pmss-pk-run-');
        // big: eligible; small: below the floor; noinst: no installer; has: already has Pikkumies; done: tried before
        $ram = ['big' => 4096, 'small' => 256, 'noinst' => 4096, 'has' => 4096, 'done' => 4096];
        foreach (array_keys($ram) as $user) {
            $this->pmssEnsureDir($root.'/home/'.$user.'/bin');
            if ($user !== 'noinst') $this->pmssWriteFile($root.'/home/'.$user.'/bin/install-pikkumies.sh', "#!/usr/bin/env bash\n");
        }
        $this->pmssWriteFile($root.'/home/has/.mcxPikkumies/pikkumies/local/hermes-managed/config.yaml', "model: x\n");
        $this->pmssWriteFile($root.'/state/done', '');
        $this->pmssWriteExecutableFile($root.'/stub/id', "#!/bin/sh\necho 4321\n");
        $runs = [];
        $ops = [
            'ramMiB' => static function (string $user) use ($ram): int { return $ram[$user]; },
            'run' => $this->pmssMakeArrayLogger($runs),
            'homeBase' => $root.'/home',
        ] + self::pmssMakeCallbacks(['users' => array_merge(array_keys($ram), ['../etc']), 'freeSpace' => 102 * 1073741824]);
        $this->pmssWithPathPrefix($root.'/stub', function () use ($root, $ops, &$marked): void {
            \pmssPikkumiesPreinstallExistingRun($root.'/state', $ops);
            $marked = array_map('basename', glob($root.'/state/*'));
            // the install did not run (stubbed), so the next update starts it again
            \pmssPikkumiesPreinstallExistingRun($root.'/state', $ops);
            touch($root.'/state/big'); // what the chain does when the install exits 0
            \pmssPikkumiesPreinstallExistingRun($root.'/state', $ops);
        });

        sort($marked);
        $this->assertSame(['done', 'has'], $marked, 'only an existing install is marked up front; below the floor, without an installer or not yet installed stay unmarked');
        $this->assertSame(2, count($runs), 'unmarked: retried; marked after success: never again: '.implode(' | ', $runs));
        foreach (['nohup sh -c ', 'systemd-run', 'PIKKUMIES_SKIP_HERMES=1', 'install-pikkumies.sh'] as $part) {
            $this->assertTrue(strpos($runs[0], $part) !== false, "chain lacks $part: ".$runs[0]);
        }
        $this->assertSame(1, substr_count($runs[0], 'install-pikkumies.sh'), 'only one account is eligible: '.$runs[0]);
        $this->assertTrue(strpos($runs[0], ") && touch -- '\\''".$root."/state/big'\\''") !== false, 'the state file must follow a successful install only: '.$runs[0]);
    }
    public function testSpaceFloorSkipsWholeRunAndMeasuresHomeOnce(): void
    {
        require_once dirname(__DIR__, 2).'/update/apps/pikkumiesPreinstall.php';
        $root = $this->pmssMakeTempDir('pmss-pk-space-');
        foreach (['fixt01', 'fixt02'] as $user) {
            $this->pmssWriteFile($root.'/home/'.$user.'/bin/install-pikkumies.sh', "#!/bin/sh\n");
        }
        $this->pmssWriteExecutableFile($root.'/stub/id', "#!/bin/sh\necho 4321\n");
        $floor = 104 * 1073741824;
        $oldLog = ini_get('error_log');
        ini_set('error_log', $root.'/hook.log');
        try {
            // Two eligible accounts need 104 GiB; invalid readings also leave the run for later.
            foreach ([0, $floor - 1, false, null, -1, INF, NAN, $floor, $floor + 1] as $free) {
                $runs = $reads = [];
                $this->pmssWriteFile($root.'/hook.log', '');
                $ops = [
                    'homeBase' => $root.'/home',
                    'run' => $this->pmssMakeArrayLogger($runs),
                    'freeSpace' => static function (string $path) use ($free, &$reads) {
                        $reads[] = $path;
                        return $free;
                    },
                ] + self::pmssMakeCallbacks(['users' => ['fixt01', 'fixt02'], 'ramMiB' => 4096]);
                $this->pmssWithPathPrefix($root.'/stub', function () use ($root, $ops): void {
                    \pmssPikkumiesPreinstallExistingRun($root.'/state', $ops);
                });
                $this->assertSame([$root.'/home'], $reads, 'one reading on the home filesystem per run');
                $allowed = is_numeric($free) && is_finite((float) $free) && $free >= $floor;
                $this->assertSame($allowed ? 1 : 0, count($runs));
                $log = file_get_contents($root.'/hook.log');
                if (!$allowed) {
                    $this->assertSame(1, substr_count($log, '[SKIP] pikkumies preinstall:'));
                    $this->assertStringContainsAllStrings(['/home free space ', 'floor 104.00 GiB', 'no installs started'], $log);
                    $this->assertStringContainsString($free === $floor - 1 ? '104.00 GiB' : ($free === 0 ? '0.00 GiB' : 'unavailable'), $log);
                } else {
                    $this->assertSame(2, substr_count($runs[0], 'install-pikkumies.sh'));
                }
                $this->assertSame([], glob($root.'/state/*'), 'no completion files before installs succeed');
            }
        } finally {
            ini_set('error_log', $oldLog);
        }
    }

    public function testMarkerPathIsMadeInARootOnlyDirectoryAndRefusesLinksAndRepeats(): void
    {
        require_once dirname(__DIR__, 2).'/user/pikkumiesPreinstallState.php';
        $root = $this->pmssMakeTempDir('pmss-pk-path-');
        $state = $root.'/state';
        $this->assertSame($state.'/fixt01', \pmssPikkumiesPreinstallStateMarkerPath('fixt01', $state));
        $this->assertSame(0700, fileperms($state) & 0777, 'the state directory is created root-only');
        $this->assertSame(null, \pmssPikkumiesPreinstallStateMarkerPath('../etc', $state));
        touch($state.'/fixt01');
        $this->assertSame(null, \pmssPikkumiesPreinstallStateMarkerPath('fixt01', $state), 'already marked');
        symlink($root.'/absent', $state.'/fixt02');
        $this->assertSame(null, \pmssPikkumiesPreinstallStateMarkerPath('fixt02', $state), 'a link is never a marker target');
        $this->pmssEnsureDir($root.'/target');
        symlink($root.'/target', $root.'/state-link');
        $this->assertSame(null, \pmssPikkumiesPreinstallStateMarkerPath('fixt03', $root.'/state-link'));
        $this->assertSame([], glob($root.'/target/*'));
    }

    public function testNewAccountIsMarkedOnlyAfterItsInstallSucceeds(): void
    {
        // A new account's preinstall writes the same marker as the update hook, after a successful install only, so a
        // later removal is respected and a failed download is tried again (ADR 0090 amendment 2026-10-20).
        require_once dirname(__DIR__, 2).'/user/add/postProvision.php';
        $root = $this->pmssMakeTempDir('pmss-pk-new-');
        $home = $root.'/account';
        $this->pmssWriteFile($home.'/bin/install-pikkumies.sh', "#!/usr/bin/env bash\n");
        $this->pmssWriteExecutableFile($root.'/stub/id', "#!/bin/sh\necho 4321\n");
        $this->pmssWithPathPrefix($root.'/stub', function () use ($root, $home, &$command): void {
            $command = (string) \pmssAddUserPikkumiesPreinstallCommand(['name' => 'fixt01', 'memory' => '2000'], $home, $root.'/state');
        });
        $this->assertTrue(strpos($command, ") && touch -- '\\''".$root."/state/fixt01'\\''") !== false, 'the marker must follow a successful install: '.$command);
        $this->assertFalse(file_exists($root.'/state/fixt01'), 'nothing is marked before the install has run');
    }
}
