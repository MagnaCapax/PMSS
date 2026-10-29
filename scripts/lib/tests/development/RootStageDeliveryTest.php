<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise fallback policy and routing without quota commands or root publication. */
class RootStageDeliveryTest extends TestCase
{
    public function testEligibilityArithmeticAndSpaceReserve(): void
    {
        $result = $this->runFixture(<<<'PHP'
$quotaCalls = 0; $paths = [];
$ops = [
    'mount' => function ($root) use (&$paths) { $paths[] = $root; return '/quota mount'; },
    'repquota' => function ($mount) use (&$quotaCalls, &$paths) {
        ++$quotaCalls; $paths[] = $mount;
        return ['#1001 -- 100 200 300 1 0 0', '#1002 +- 299 0 300 1 0 0',
            '#1003 +- 200 200 0 1 0 0', '#1004 -- 298 0 300 1 0 0', '#1005 -- 999 0 0 1 0 0'];
    },
];
$cases = [
    ['1001', 1024, 999999, 999999999], ['1002', 1024, 999999, 999999999],
    ['1003', 1024, 999999, 999999999], ['1004', 1024, 999999, 999999999],
    ['1004', 1025, 999999, 999999999], ['1005', 1024, 999999, 999999999],
    ['9999', 1024, 999999, 999999999], ['9999', 1024, 66559, 999999999],
    ['9999', 1024, 66560, 999999999], ['9999', 1024, 0, 268436480],
    ['9999', 1024, 0, 268436481], ['1002', 1024, 999999, 268436480],
    ['9999', 1024, false, 999999999], ['9999', 1024, 0, null], ['9999', 1024, 0, 268436479],
];
$reasons = [];
foreach ($cases as [$user, $bytes, $free, $rootFree]) {
    $ops['freeBytes'] = function ($home) use ($free) { return $free; };
    $ops['rootFreeBytes'] = function ($root) use ($rootFree) { return $rootFree; };
    $reasons[] = pmssUpdateUserRootStageEligible($user, '/fixture/'.$user, $bytes, $ops);
}
echo json_encode([$reasons, $quotaCalls, $paths]);
PHP
        );
        $this->assertSame([null, 'over quota', 'over quota', null, 'over quota', null, null,
            'home full', null, 'root reserve low', 'home full', 'root reserve low', null,
            'root reserve low', 'root reserve low'], $result[0]);
        $this->assertSame(1, $result[1]);
        $this->assertSame(['/fixture', '/quota mount'], $result[2]);
    }

    public function testFailedQuotaObservationIsCachedAndOnlySpaceApplies(): void
    {
        $result = $this->runFixture(<<<'PHP'
$calls = 0;
$ops = ['mount' => function () { return '/'; },
    'repquota' => function () use (&$calls) { ++$calls; return null; },
    'freeBytes' => function () { return 999999; }, 'rootFreeBytes' => function () { return 999999999; }];
$first = pmssUpdateUserRootStageEligible('1002', '/fixture/1002', 1024, $ops);
$ops['freeBytes'] = function () { return 0; };
$second = pmssUpdateUserRootStageEligible('1002', '/fixture/1002', 1024, $ops);
echo json_encode([$first, $second, $calls]);
PHP
        );
        $this->assertSame([null, 'home full', 1], $result);
    }

    public function testDeliveryUsesPrimaryGroupLiteralModeAndReportsFailures(): void
    {
        $result = $this->runFixture(<<<'PHP'
$results = [];
foreach (['1001', 'missing', 'badgroup', '1001'] as $index => $user) {
    $GLOBALS['stageOk'] = $index !== 3; $failure = 'stale';
    $ok = pmssUpdateUserRootStageDeliver($user, '/fixture/1001/file', 'panel', $failure);
    $results[] = [$ok, $failure];
}
echo json_encode([$results, $GLOBALS['stageCalls']]);
PHP
        );
        $this->assertSame([[true, ''], [false, 'account primary group unavailable'],
            [false, 'account primary group unavailable'], [false, 'stage=publish rc=23']], $result[0]);
        $this->assertSame(array_fill(0, 2, ['/fixture/1001/file', 'panel', 'account-primary', 0755, false]), $result[1]);
    }

    public function testCountsAccumulatePerUserAndDrainAfterReporting(): void
    {
        $result = $this->runFixture(<<<'PHP'
pmssUpdateUserRootStageCounts('1001', true); pmssUpdateUserRootStageCounts('1002', false);
pmssUpdateUserRootStageCounts('1001', false); pmssUpdateUserRootStageCounts('1001', true);
echo json_encode([pmssUpdateUserRootStageCounts('1001'), pmssUpdateUserRootStageCounts('1002'),
    pmssUpdateUserRootStageCounts('1001'), pmssUpdateUserRootStageCounts('1002')]);
PHP
        );
        $this->assertSame([[2, 1], [0, 1], [0, 0], [0, 0]], $result);
    }

    public function testCopyRoutesOnlyFailedEligibleWritesAndCountsResults(): void
    {
        $root = $this->pmssMakeTrackedHomeRoot('pmss-root-delivery-');
        $home = $this->pmssEnsureDir($root.'/1001');
        $source = $this->pmssWriteFile($root.'/source', 'panel');
        $script = '$source = '.var_export($source, true).'; $target = '.var_export($home.'/file', true).';';
        $script .= <<<'PHP'
$results = [];
foreach ([[true, null, true], [false, 'over quota', true], [false, null, true],
    [false, 'home full', false], [false, 'root reserve low', true]] as [$accountOk, $reason, $stageOk]) {
    $events = []; $logs = []; $GLOBALS['stageCalls'] = []; $GLOBALS['stageOk'] = $stageOk;
    $ops = [
        'accountWrite' => function (...$args) use (&$events, $accountOk) { $events[] = $args; return $accountOk; },
        'eligible' => function (...$args) use (&$events, $reason) { $events[] = $args; return $reason; },
        'deliver' => __NAMESPACE__.'\pmssUpdateUserRootStageDeliver',
        'log' => function ($line) use (&$logs) { $logs[] = $line; },
    ];
    $ok = \copyToUserSpace($source, $target, '1001', $ops);
    $results[] = [$ok, $events, $logs, $GLOBALS['stageCalls'], \pmssUpdateUserRootStageCounts('1001')];
}
echo json_encode($results);
PHP;
        $result = $this->runFixture($script, ['PMSS_HOME_DIR' => $root]);
        foreach ($result as $index => [$ok, $events, $logs, $stageCalls, $counts]) {
            $this->assertSame($index < 2, $ok);
            $this->assertSame(['1001', $home, $home.'/file', 'panel', 0755, true], $events[0]);
            $this->assertSame($index === 0 ? 1 : 2, count($events));
            if ($index > 0) $this->assertSame(['1001', $home, 5], $events[1]);
            $this->assertSame(in_array($index, [1, 3], true) ? 1 : 0, count($stageCalls));
            $this->assertSame($index === 1 ? [1, 0] : ($index === 3 ? [0, 1] : [0, 0]), $counts);
        }
        $this->assertSame([], $result[0][2]);
        $this->assertSame(["[user:1001] Delivered {$home}/file through the root stage (over quota)"], $result[1][2]);
        $this->assertSame(["[user:1001] Failed to copy {$source} to {$home}/file"], $result[2][2]);
        $this->assertSame(["[user:1001] Root-stage delivery of {$home}/file failed: stage=publish rc=23"], $result[3][2]);
        $this->assertStringContainsString('skipped: root reserve low', $result[4][2][0]);
    }

    /** Isolate the static quota snapshot and stub privileged I/O in a child namespace. */
    private function runFixture(string $script, array $environment = []): array
    {
        $fixture = <<<'PHP'
namespace RootStageDeliveryFixture;
function posix_getpwnam($user) { return $user === 'missing' ? false : ['uid' => (int) $user, 'gid' => $user === 'badgroup' ? -1 : 123]; }
function posix_getgrgid($gid) { return $gid === -1 ? false : ['name' => 'account-primary']; }
function pmssCommandCapture($command) { throw new \RuntimeException('Unexpected command: '.$command); }
function disk_free_space($path) { throw new \RuntimeException('Unexpected disk observation'); }
function pmssRootStageFileWrite($target, $content, $group, $mode, $immutable, &$failure) {
    $GLOBALS['stageCalls'][] = [$target, $content, $group, $mode, $immutable];
    $failure = ($GLOBALS['stageOk'] ?? true) ? '' : 'stage=publish rc=23';
    return $GLOBALS['stageOk'] ?? true;
}
PHP;
        $fixture .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/update.php'), true).';';
        $fixture .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/users/rootStageDelivery.php', 'RootStageDeliveryFixture');
        return $this->pmssRunInlinePhpJson($fixture.$script, $environment + ['PMSS_HOME_DIR' => '/fixture']);
    }
}
