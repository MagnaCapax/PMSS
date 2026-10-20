<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Existing-home Pikkumies preinstall (ADR 0090): host marker gate, RAM floor, installer present, once per account. */
final class PikkumiesPreinstallHookTest extends TestCase
{
    public function testLifecycleMarkerClearAcceptsOnlyFilesAndLinks(): void
    {
        require_once dirname(__DIR__, 2).'/user/pikkumiesPreinstallState.php';
        $root = $this->pmssMakeTempDir('pmss-pk-clear-');
        $state = $root.'/state';
        $this->pmssEnsureDir($state);

        file_put_contents($state.'/alice', 'done');
        $this->assertTrue(\pmssPikkumiesPreinstallStateClear('alice', $state));
        $this->assertFalse(file_exists($state.'/alice'));
        $this->assertTrue(\pmssPikkumiesPreinstallStateClear('alice', $state));

        file_put_contents($root.'/target', 'keep');
        symlink($root.'/target', $state.'/alice');
        $this->assertTrue(\pmssPikkumiesPreinstallStateClear('alice', $state));
        $this->assertFalse(is_link($state.'/alice'));
        $this->assertSame('keep', file_get_contents($root.'/target'));

        $this->pmssEnsureDir($state.'/alice');
        $this->assertFalse(\pmssPikkumiesPreinstallStateClear('alice', $state));
        $this->assertTrue(is_dir($state.'/alice'));
        $this->assertFalse(\pmssPikkumiesPreinstallStateClear('../target', $state));
        $this->assertSame('keep', file_get_contents($root.'/target'));

        symlink($state, $root.'/state-link');
        $this->assertFalse(\pmssPikkumiesPreinstallStateClear('alice', $root.'/state-link'));
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
        $this->pmssAssertRepoFileContractCases([
            'scripts/recreateUser.php' => ['ordered' => [[
                "pmssRunOrExit('cp -Rp /etc/skel ' . escapeshellarg(\$homeDir));",
                'pmssPikkumiesPreinstallStateClear($userName)',
            ]]],
            'scripts/terminateUser.php' => ['ordered' => [[
                'pmssTerminateUserFinalRemovalSteps($username, $uid, $killUserCommand)',
                'pmssTerminateUserClearPreinstallState($username, $dryRun, pmssUserAccountLookup($username) !== null)',
            ]], 'required' => [
                'pmssTerminateUserClearPreinstallState',
            ]],
        ]);
    }

    public function testAbsentMarkerIsInert(): void
    {
        $this->assertTrue(include_once dirname(__DIR__, 2).'/update/apps/pikkumiesPreinstall.php');
        $root = $this->pmssMakeTempDir('pmss-pk-inert-');
        $events = [];
        $throw = static function () use (&$events): array { $events[] = 'called'; throw new \RuntimeException('called'); };
        \pmssPikkumiesPreinstallExistingRun($root.'/absent-marker', $root.'/state', ['users' => $throw, 'ramMiB' => $throw, 'run' => $throw]);
        $this->assertSame([], $events);
        $this->assertSame([], glob($root.'/*'));
    }

    public function testLinkedStateDirectoryAndMarkerAreNotWritten(): void
    {
        require_once dirname(__DIR__, 2).'/update/apps/pikkumiesPreinstall.php';
        $root = $this->pmssMakeTempDir('pmss-pk-links-');
        file_put_contents($root.'/marker', '');
        $this->pmssEnsureDir($root.'/target');
        symlink($root.'/target', $root.'/state-link');
        $calls = [];
        $ops = [
            'users' => static function () use (&$calls): array { $calls[] = 'users'; return ['alice']; },
            'run' => static function () use (&$calls): void { $calls[] = 'run'; },
        ];
        \pmssPikkumiesPreinstallExistingRun($root.'/marker', $root.'/state-link', $ops);
        $this->assertSame([], $calls);
        $this->assertSame([], glob($root.'/target/*'));

        $this->pmssEnsureDir($root.'/state');
        symlink($root.'/absent', $root.'/state/alice');
        \pmssPikkumiesPreinstallExistingRun($root.'/marker', $root.'/state', $ops);
        $this->assertSame(['users'], $calls);
        $this->assertFalse(file_exists($root.'/absent'));
    }

    public function testStartsOnlyEligibleAccountsOnceInOneBackgroundChain(): void
    {
        require_once dirname(__DIR__, 2).'/update/apps/pikkumiesPreinstall.php';
        $root = $this->pmssMakeTempDir('pmss-pk-run-');
        file_put_contents($root.'/marker', '');
        // big: eligible; small: below the floor; noinst: no installer; has: already has Pikkumies; done: tried before
        $ram = ['big' => 4096, 'small' => 256, 'noinst' => 4096, 'has' => 4096, 'done' => 4096];
        foreach (array_keys($ram) as $user) {
            $this->pmssEnsureDir($root.'/home/'.$user.'/bin');
            if ($user !== 'noinst') file_put_contents($root.'/home/'.$user.'/bin/install-pikkumies.sh', "#!/usr/bin/env bash\n");
        }
        $this->pmssEnsureDir($root.'/home/has/.mcxPikkumies');
        $this->pmssEnsureDir($root.'/state');
        touch($root.'/state/done');
        $this->pmssEnsureDir($root.'/stub');
        file_put_contents($root.'/stub/id', "#!/bin/sh\necho 4321\n");
        chmod($root.'/stub/id', 0755);
        $path = (string) getenv('PATH');
        $runs = [];
        $ops = [
            'users' => static function () use ($ram): array { return array_merge(array_keys($ram), ['../etc']); },
            'ramMiB' => static function (string $user) use ($ram): int { return $ram[$user]; },
            'run' => static function (string $command) use (&$runs): void { $runs[] = $command; },
            'homeBase' => $root.'/home',
        ];
        putenv('PATH='.$root.'/stub:'.$path);
        try {
            \pmssPikkumiesPreinstallExistingRun($root.'/marker', $root.'/state', $ops);
            $marked = array_map('basename', glob($root.'/state/*'));
            // the install did not run (stubbed), so the next update starts it again
            \pmssPikkumiesPreinstallExistingRun($root.'/marker', $root.'/state', $ops);
            touch($root.'/state/big'); // what the chain does when the install exits 0
            \pmssPikkumiesPreinstallExistingRun($root.'/marker', $root.'/state', $ops);
        } finally {
            putenv('PATH='.$path);
        }

        sort($marked);
        $this->assertSame(['done', 'has'], $marked, 'only an existing install is marked up front; below the floor, without an installer or not yet installed stay unmarked');
        $this->assertSame(2, count($runs), 'unmarked: retried; marked after success: never again: '.implode(' | ', $runs));
        foreach (['nohup sh -c ', 'systemd-run', 'PIKKUMIES_SKIP_HERMES=1', 'install-pikkumies.sh'] as $part) {
            $this->assertTrue(strpos($runs[0], $part) !== false, "chain lacks $part: ".$runs[0]);
        }
        $this->assertSame(1, substr_count($runs[0], 'install-pikkumies.sh'), 'only one account is eligible: '.$runs[0]);
        $this->assertTrue(strpos($runs[0], ") && touch -- '\\''".$root."/state/big'\\''") !== false, 'the state file must follow a successful install only: '.$runs[0]);
    }
public function testMarkerPathIsMadeInARootOnlyDirectoryAndRefusesLinksAndRepeats(): void
    {
        require_once dirname(__DIR__, 2).'/user/pikkumiesPreinstallState.php';
        $root = $this->pmssMakeTempDir('pmss-pk-path-');
        $state = $root.'/state';
        $this->assertSame($state.'/alice', \pmssPikkumiesPreinstallStateMarkerPath('alice', $state));
        $this->assertSame(0700, fileperms($state) & 0777, 'the state directory is created root-only');
        $this->assertSame(null, \pmssPikkumiesPreinstallStateMarkerPath('../etc', $state));
        touch($state.'/alice');
        $this->assertSame(null, \pmssPikkumiesPreinstallStateMarkerPath('alice', $state), 'already marked');
        symlink($root.'/absent', $state.'/bob');
        $this->assertSame(null, \pmssPikkumiesPreinstallStateMarkerPath('bob', $state), 'a link is never a marker target');
        $this->pmssEnsureDir($root.'/target');
        symlink($root.'/target', $root.'/state-link');
        $this->assertSame(null, \pmssPikkumiesPreinstallStateMarkerPath('carol', $root.'/state-link'));
        $this->assertSame([], glob($root.'/target/*'));
    }

    public function testNewAccountIsMarkedOnlyAfterItsInstallSucceeds(): void
    {
        // A new account's preinstall writes the same marker as the update hook, after a successful install only, so a
        // later removal is respected and a failed download is tried again (ADR 0090 amendment 2026-10-20).
        require_once dirname(__DIR__, 2).'/user/add/postProvision.php';
        $root = $this->pmssMakeTempDir('pmss-pk-new-');
        $home = $root.'/account';
        $this->pmssEnsureDir($home.'/bin');
        file_put_contents($home.'/bin/install-pikkumies.sh', "#!/usr/bin/env bash\n");
        $this->pmssEnsureDir($root.'/stub');
        file_put_contents($root.'/stub/id', "#!/bin/sh\necho 4321\n");
        chmod($root.'/stub/id', 0755);
        $path = (string) getenv('PATH');
        putenv('PATH='.$root.'/stub:'.$path);
        try {
            $command = (string) \pmssAddUserPikkumiesPreinstallCommand(['name' => 'alice', 'memory' => '2000'], $home, $root.'/state');
        } finally {
            putenv('PATH='.$path);
        }
        $this->assertTrue(strpos($command, ") && touch -- '\\''".$root."/state/alice'\\''") !== false, 'the marker must follow a successful install: '.$command);
        $this->assertFalse(file_exists($root.'/state/alice'), 'nothing is marked before the install has run');
    }
}
