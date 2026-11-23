<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/rtorrent/watchdogOptOutRepair.php';

/** An rTorrent opt-out account keeps its panel route: the rc is restored and the route requested, nothing starts. */
class RtorrentOptOutRouteRepairTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-optout-repair-']; }

    /** Home, nginx route dir and state dir; the rc and route exist only when asked for. */
    private function fixture(bool $rc, bool $route): array
    {
        $home = $this->tempDir.'/home/fixtur01';
        $routes = $this->tempDir.'/routes';
        $state = $this->tempDir.'/state';
        foreach ([$home, $routes, $state] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0700, true);
        }
        foreach ([$home.'/.rtorrent.rc', $routes.'/fixtur01', $state.'/checkRtorrent-optout-repair-fixtur01.ts'] as $file) {
            if (is_file($file)) unlink($file);
        }
        if ($rc) file_put_contents($home.'/.rtorrent.rc', "# rc\n");
        if ($route) file_put_contents($routes.'/fixtur01', "# route\n");
        return [$home, $routes, $state];
    }

    /** Run the repair with recording callbacks; returns [result, calls]. */
    private function repair(array $paths, bool $recovers, bool $requests, ?int $now = 1000000): array
    {
        [$home, $routes, $state] = $paths;
        $calls = [];
        $result = \pmssCheckRtorrentOptOutRepair('fixtur01', $home, $state,
            function (string $user, string $h) use (&$calls, $recovers): bool {
                $calls[] = ['recover', $user, $h];
                return $recovers;
            },
            function (string $user) use (&$calls, $requests): bool {
                $calls[] = ['route', $user];
                return $requests;
            }, $now, $routes);
        return [$result, $calls];
    }

    public function testHealthyAccountCostsNothingAndWritesNoState(): void
    {
        $paths = $this->fixture(true, true);
        [$result, $calls] = $this->repair($paths, true, true);
        $this->assertSame('healthy', $result);
        $this->assertSame([], $calls);
        $this->assertSame(['.', '..'], scandir($paths[2]));
    }

    public function testMissingRcIsRecoveredThenTheRouteIsRequested(): void
    {
        $paths = $this->fixture(false, false);
        [$result, $calls] = $this->repair($paths, true, true);
        $this->assertSame('route-requested', $result);
        $this->assertSame([['recover', 'fixtur01', $paths[0]], ['route', 'fixtur01']], $calls);
        $this->assertSame('1000000', file_get_contents($paths[2].'/checkRtorrent-optout-repair-fixtur01.ts'));
    }

    public function testRcRecoveredWhileTheRouteStillExistsReloadsNothing(): void
    {
        // An account can delete its own rc; that must not cost the shared nginx a reload.
        $paths = $this->fixture(false, true);
        [$result, $calls] = $this->repair($paths, true, true);
        $this->assertSame('config-recovered', $result);
        $this->assertSame([['recover', 'fixtur01', $paths[0]]], $calls);
    }

    public function testFailedRecoveryRequestsNoRoute(): void
    {
        [$result, $calls] = $this->repair($this->fixture(false, false), false, true);
        $this->assertSame('config-failed', $result);
        $this->assertSame([['recover', 'fixtur01', $this->tempDir.'/home/fixtur01']], $calls);
    }

    public function testRcPresentButRouteAbsentRequestsOnlyTheRoute(): void
    {
        [$result, $calls] = $this->repair($this->fixture(true, false), true, true);
        $this->assertSame('route-requested', $result);
        $this->assertSame([['route', 'fixtur01']], $calls);
    }

    public function testRouteRequestIsThrottledThenRetriedAfterTheWindow(): void
    {
        $paths = $this->fixture(true, false);
        $this->assertSame('route-failed', $this->repair($paths, true, false, 1000000)[0]);
        [$result, $calls] = $this->repair($paths, true, true, 1000000 + PMSS_RTORRENT_OPTOUT_REPAIR_INTERVAL - 1);
        $this->assertSame('throttled', $result);
        $this->assertSame([], $calls);
        [$result, $calls] = $this->repair($paths, true, true, 1000000 + PMSS_RTORRENT_OPTOUT_REPAIR_INTERVAL);
        $this->assertSame('route-requested', $result);
        $this->assertSame([['route', 'fixtur01']], $calls);
    }

    public function testUnwritableStateFailsClosedWithoutAnyAttempt(): void
    {
        $paths = $this->fixture(false, false);
        $paths[2] = $this->tempDir.'/no-such-state-dir';
        [$result, $calls] = $this->repair($paths, true, true);
        $this->assertSame('state-unwritable', $result);
        $this->assertSame([], $calls);
    }

    /** An account that lost rc and route before this change, with no attempt marker (fresh boot): fixed at the first cycle, then quiet. */
    public function testFirstCycleAfterTheUpdateRepairsAnAlreadyBrokenAccountThenItStaysQuiet(): void
    {
        $paths = $this->fixture(false, false);
        $this->assertSame(['.', '..'], scandir($paths[2]));
        [$result, $calls] = $this->repair($paths, true, true, 2000000);
        $this->assertSame('route-requested', $result);
        $this->assertSame(2, count($calls));
        // The repair produced what the generator needs; the next cycle finds a healthy account and does nothing.
        file_put_contents($paths[0].'/.rtorrent.rc', "# recovered\n");
        file_put_contents($paths[1].'/fixtur01', "# route\n");
        [$result, $calls] = $this->repair($paths, true, true, 2000000 + 120);
        $this->assertSame('healthy', $result);
        $this->assertSame([], $calls);
    }

    /** Repairing one account must not touch another account's home, route or attempt marker. */
    public function testRepairForOneAccountLeavesAnotherAccountUntouched(): void
    {
        $paths = $this->fixture(false, false);
        $other = $this->tempDir.'/home/fixtur02';
        mkdir($other, 0700, true);
        file_put_contents($other.'/.rtorrent.rc', "# other\n");
        file_put_contents($paths[1].'/fixtur02', "# other route\n");
        $calls = [];
        $recover = function (string $user, string $h) use (&$calls): bool { $calls[] = ['recover', $user]; return true; };
        $route = function (string $user) use (&$calls): bool { $calls[] = ['route', $user]; return true; };

        $this->assertSame('route-requested', \pmssCheckRtorrentOptOutRepair('fixtur01', $paths[0], $paths[2], $recover, $route, 3000000, $paths[1]));
        $this->assertSame('healthy', \pmssCheckRtorrentOptOutRepair('fixtur02', $other, $paths[2], $recover, $route, 3000000, $paths[1]));

        $this->assertSame([['recover', 'fixtur01'], ['route', 'fixtur01']], $calls);
        $this->assertSame(['.', '..', 'checkRtorrent-optout-repair-fixtur01.ts'], scandir($paths[2]));
        $this->assertSame("# other\n", file_get_contents($other.'/.rtorrent.rc'));
        $this->assertSame("# other route\n", file_get_contents($paths[1].'/fixtur02'));
    }

    public function testCronMarkerBranchRepairsBeforeContinuingAndStartsNothing(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');
        $start = strpos($source, "if (is_link(\$home.'/.rtorrentDisable')");
        $end = strpos($source, 'if (pmssUserWatchdogBackoffActive($user, \'rtorrent\')) continue;');
        $this->assertTrue($start !== false && $end !== false && $start < $end);
        $block = substr($source, $start, $end - $start);
        $this->assertOrderedStrings([
            'rtorrentProcessKillManagedPids($user, SIGTERM)',
            'pmssCheckRtorrentOptOutRepair(',
            'pmssCheckRtorrentRecoverMissingConfig(',
            "'/scripts/util/createNginxConfig.php', ['--user', \$repairUser, '--reload']",
            'continue;',
        ], $block);
        foreach (['rtorrentProcessRestart', 'startRtorrent', 'pmssCheckRtorrentHandleMissingProcess'] as $starter) {
            $this->assertStringNotContainsString($starter, $block);
        }
    }
}
