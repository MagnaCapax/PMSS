<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Keep the real CLI includes and command runner; isolate account state and service startup. */
final class LighttpdSymlinkRepairCliTest extends TestCase
{
    /** Observe CLI exits in a fresh process without touching a real account or daemon. */
    private function observe(array $case = []): array
    {
        $fixture = $this->pmssMakeTempDir('pmss-lighttpd-repair-cli-');
        // The starter records its argument so constructing a command alone cannot pass.
        $this->pmssWriteExecutableFiles($fixture, [
            'startLighttpd' => "#!/bin/sh\nprintf '%s\\n' \"\$1\" > "
                .escapeshellarg($fixture.'/started')."\nexit ".(int) ($case['startRc'] ?? 0)."\n",
        ]);
        $source = $this->pmssReadRepoFile('scripts/util/lighttpdSymlinkRepair.php');
        // Strip the CLI shebang and tag, preserving every require (including runStep's definition).
        $source = substr($source, strpos($source, '<?php') + 5);
        $source = str_replace('__DIR__', var_export($this->pmssRepoPath('scripts/util'), true), $source);
        $script = <<<'PHP'
namespace LighttpdSymlinkRepairCliFixture;
// Substitute identity and account state only in the entry point's namespace.
// Required libraries remain global and runStep must come from the real include chain.
function requireRoot() { $GLOBALS['events'][] = 'root'; }
function posix_getpwnam($user) { return $GLOBALS['case']['account'] ?? ['uid' => 1234]; }
function fileowner($path) { return $GLOBALS['case']['owner'] ?? 1234; }
function is_dir($path) { return $GLOBALS['case']['homeExists'] ?? true; }
function pmssPathTargetIsSafe($path, $directory) { return $GLOBALS['case']['homeSafe'] ?? true; }
function is_link($path) {
    return in_array(basename($path), $GLOBALS['case']['links'] ?? ['upload', 'access.log', 'error.log'], true);
}
function pmssLighttpdSymlinkRepairQuotaAllows($user) {
    $GLOBALS['events'][] = 'quota';
    return $GLOBALS['case']['quotaAllows'] ?? true;
}
function pmssLighttpdSymlinkRepairOne($user, $home, $name, $directory) {
    $GLOBALS['events'][] = ['repair', $user, $name, $directory];
    return $name !== ($GLOBALS['case']['repairFails'] ?? '');
}
function pmssBuildCommand($binary, $arguments) {
    $GLOBALS['events'][] = ['start', basename($binary), $arguments];
    return \pmssBuildCommand($GLOBALS['fixture'].'/startLighttpd', $arguments);
}
PHP;
        // CLI exit() still flushes the event trace, including on an early refusal or fatal error.
        $script .= '$GLOBALS["case"] = '.var_export($case, true).';'
            .'$GLOBALS["fixture"] = '.var_export($fixture, true).';'
            .'$GLOBALS["events"] = [];'
            .'$argv = '.var_export($case['argv'] ?? ['script', 'fixtur01'], true).'; $argc = count($argv);'
            .'register_shutdown_function(static function () {'
            .' file_put_contents($GLOBALS["fixture"]."/events.json", json_encode($GLOBALS["events"])); });'
            .'eval('.var_export('namespace LighttpdSymlinkRepairCliFixture;'.$source, true).');';
        $result = $this->pmssExecInlinePhp($script, [
            'PMSS_HOME_DIR' => $fixture.'/home',
            'PMSS_DRY_RUN' => !empty($case['dryRun']) ? '1' : '0',
            // The real runner may log; both channels belong to this temporary fixture.
            'PMSS_LOG_DIR' => $fixture,
            'PMSS_JSON_LOG' => $fixture.'/update.jsonl',
            'PMSS_CORRELATION_ID' => 'lighttpd-repair-fixture',
        ]);
        return [$result, $this->pmssDecodeJsonArray((string) file_get_contents($fixture.'/events.json')),
            is_file($fixture.'/started') ? file_get_contents($fixture.'/started') : null];
    }

    public function testRepairsBeforeStartingAndPropagatesStarterStatus(): void
    {
        // The original missing-runStep failure must prevent the starter marker and fail this test.
        foreach ([0, 7] as $rc) {
            [$result, $events, $started] = $this->observe(['startRc' => $rc]);
            $this->assertSame($rc, $result['rc'], $result['output']);
            $this->assertSame(['root', 'quota', ['repair', 'fixtur01', 'upload', true],
                ['repair', 'fixtur01', 'access.log', false], ['repair', 'fixtur01', 'error.log', false],
                ['start', 'startLighttpd', ['fixtur01']]], $events);
            $this->assertSame("fixtur01\n", $started);
        }
    }

    public function testDryRunNeverProbesQuotaRepairsOrStarts(): void
    {
        [$result, $events, $started] = $this->observe(['dryRun' => true]);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame(['root'], $events);
        $this->assertSame(null, $started);
        $this->assertTrue(strpos($result['output'], 'links=upload,access.log,error.log') !== false);
    }

    public function testQuotaRefusalStopsBeforeRepairAndStartup(): void
    {
        [$result, $events, $started] = $this->observe(['quotaAllows' => false]);
        $this->assertSame(1, $result['rc']);
        $this->assertSame(['root', 'quota'], $events);
        $this->assertSame(null, $started);
        $this->assertTrue(strpos($result['output'], 'Live quota does not authorize') !== false);
    }

    public function testFailedRepairStopsRemainingRepairsAndStartup(): void
    {
        // Failure midway through the path list must leave later repairs unattempted.
        [$result, $events, $started] = $this->observe(['repairFails' => 'access.log']);
        $this->assertSame(1, $result['rc']);
        $this->assertSame(['root', 'quota', ['repair', 'fixtur01', 'upload', true],
            ['repair', 'fixtur01', 'access.log', false]], $events);
        $this->assertSame(null, $started);
    }

    public function testAlreadyRepairedPathsStartWithoutQuotaOrMutation(): void
    {
        // The absence of links keeps repeat invocations independent of a quota probe.
        [$result, $events, $started] = $this->observe(['links' => [], 'quotaAllows' => false]);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame(['root', ['start', 'startLighttpd', ['fixtur01']]], $events);
        $this->assertSame("fixtur01\n", $started);
    }

    public function testInvalidArgumentsOrUnsafeHomeNeverReachRepairOrStartup(): void
    {
        foreach ([['argv' => ['script']], ['argv' => ['script', '../root']],
            ['argv' => ['script', 'fixtur01', 'extra']], ['account' => false],
            ['homeSafe' => false], ['homeExists' => false], ['owner' => 4321]] as $case) {
            [$result, $events, $started] = $this->observe($case);
            $this->assertSame(1, $result['rc'], $result['output']);
            $this->assertSame(['root'], $events);
            $this->assertSame(null, $started);
        }
    }
}
