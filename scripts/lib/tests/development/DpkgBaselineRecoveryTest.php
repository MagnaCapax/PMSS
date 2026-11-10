<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise real recovery control flow with every package command and clock stubbed. */
class DpkgBaselineRecoveryTest extends TestCase
{
    public function testBaselineApplicationKeepsStagingCommandAndCleanupOrder(): void
    {
        $result = $this->fixture(['install' => [0], 'warnings' => true, 'fuse' => true]);
        $this->assertTrue($result['ok']);
        $this->assertSame([
            ['Refreshing dpkg availability database', ' apt-cache dumpavail | dpkg --merge-avail'],
            ['read', '/fixture/selections'],
            ['sanitize', ['alpha install'], 12],
            ['stage', ['alpha install']],
            ['summary'],
            ['Applying dpkg selection baseline', "dpkg --set-selections < '/fixture/staged'"],
            ['Installing packages from selection baseline', 'apt-get dselect-upgrade -y'],
            ['unlink', '/fixture/staged'],
            ['fuse-query'],
            ['Holding fuse3 to block the fuse2 conflict-removal cascade (GH#643)', ' apt-mark hold fuse3 || true'],
            ['WARN', 'Dpkg selection baseline contained ignored entries; proceeding with remaining packages', 0],
        ], $result['trace']);
    }

    public function testMissingBaselineDoesNothingAndReturnsSuccess(): void
    {
        $result = $this->fixture(['baseline' => null]);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['trace']);
    }

    public function testApplicationReleasesStagedSelectionsBeforePropagatingThrowables(): void
    {
        foreach (['RuntimeException', 'Error'] as $class) {
            foreach ([
                'summary', 'Applying dpkg selection baseline', 'Installing packages from selection baseline',
                'Completing pending dpkg configuration', 'Attempting apt fix-broken install (dpkg baseline)',
                'Retrying package selection install', '[ERROR] Package baseline installation still failing after retry',
            ] as $boundary) {
                $result = $this->fixture(['throwAt' => $boundary, 'throwClass' => $class, 'install' => [100, 100]]);
                $this->assertTrue($result['sameFailure'], $boundary);
                $this->assertTrue($result['cleaned'], $boundary);
                $this->assertSame(['unlink', '/fixture/staged'], end($result['trace']));
                $this->assertSame(1, count(array_filter($result['trace'], static function (array $row): bool {
                    return $row[0] === 'unlink';
                })));
            }
            // No staged file exists when the availability command fails.
            $result = $this->fixture(['throwAt' => 'Refreshing dpkg availability database', 'throwClass' => $class]);
            $this->assertTrue($result['sameFailure']);
            $this->assertFalse($result['cleaned']);
        }
    }

    public function testFailedStagingRefusesRawBaselineBeforeSummaryAndPackageWrites(): void
    {
        $result = $this->fixture(['stage' => null]);
        $this->assertFalse($result['ok']);
        $this->assertSame(['Refreshing dpkg availability database', 'read', 'sanitize', 'stage'], array_column($result['trace'], 0));
        $this->assertFalse($result['cleaned']);
        $this->assertSame(['[ERROR] Refusing to apply raw dpkg selections baseline after sanitized baseline staging failed'], $result['logs']);
    }

    public function testUnreadableAndEmptyPlansKeepRawPathAndDoNotStage(): void
    {
        foreach ([['lines' => false], ['sanitised' => []]] as $options) {
            $result = $this->fixture($options + ['install' => [0]]);
            $this->assertTrue($result['ok']);
            $this->assertFalse($result['cleaned']);
            $this->assertSame([], array_values(array_filter($result['trace'], static function (array $row): bool { return $row[0] === 'stage'; })));
            $this->assertTrue(in_array(['Applying dpkg selection baseline', "dpkg --set-selections < '/fixture/selections'"], $result['trace'], true));
        }
    }

    public function testFailedSetSelectionsStillInstallsButKeepsFailedResult(): void
    {
        foreach ([[0], [100, 0]] as $install) {
            $result = $this->fixture(['setRc' => 1, 'install' => $install]);
            $this->assertFalse($result['ok']);
            $this->assertSame('install', $result['actions'][0]);
            $this->assertTrue($result['cleaned']);
        }
    }

    public function testSystemdMaskAndFailedConfigureKeepUnmaskAndRetryOrder(): void
    {
        foreach (['masked', 'enabled'] as $state) {
            $result = $this->fixture(['systemd' => true, 'unit' => $state, 'configure' => [1]]);
            $unmasks = array_values(array_filter($result['trace'], static function (array $row): bool {
                return strpos((string) ($row[1] ?? ''), 'systemctl unmask') === 0;
            }));
            $expected = $state === 'masked' ? [['command', 'systemctl unmask proftpd.service']] : [];
            $expected[] = ['Unmasking proftpd for dpkg retry', 'systemctl unmask proftpd.service || true'];
            $this->assertSame($expected, $unmasks);
            $this->assertSame(['install', 'configure', 'proftpd', 'fix', 'install'], $result['actions']);
        }
    }

    public function testTimeoutAndOrdinaryFailureConfigureDpkgBeforeFixBrokenAndRetry(): void
    {
        foreach ([124, 100, 137] as $rc) {
            $result = $this->fixture(['install' => [$rc, 0]]);
            $this->assertTrue($result['ok']);
            $this->pmssAssertArraySubsetSame(['actions' => ['install', 'configure', 'fix', 'install'], 'time' => 2], $result);
            $this->assertStringContainsString('dpkg lock cleared', implode('\n', $result['logs']));
        }
    }

    public function testSuccessfulBaselineNeedsNoRecovery(): void
    {
        $result = $this->fixture(['install' => [0]]);
        $this->assertTrue($result['ok']);
        $this->pmssAssertArraySubsetSame(['actions' => ['install'], 'sleeps' => []], $result);
    }

    public function testFailedFixBrokenCompletesDpkgAgainBeforeRetry(): void
    {
        foreach ([100, 124] as $rc) {
            $result = $this->fixture(['fix' => [$rc]]);
            $this->assertTrue($result['ok']);
            $this->pmssAssertArraySubsetSame(['actions' => ['install', 'configure', 'fix', 'configure', 'install'], 'time' => 4], $result);
        }
    }

    public function testBusyLockStopsRecoveryWithinSixtySeconds(): void
    {
        $result = $this->fixture(['release' => 1000]);
        $this->assertFalse($result['ok']);
        $this->pmssAssertArraySubsetSame(['actions' => ['install'], 'time' => 60], $result);
        $this->assertStringContainsString('did not clear within 60 seconds', implode('\n', $result['logs']));
        $this->assertTrue($result['cleaned'], 'staged selections must be cleaned even when locks stay busy');
    }

    public function testConfigureTimeoutWaitsBeforeProftpdFallbackAndApt(): void
    {
        $result = $this->fixture(['configure' => [124]]);
        $this->assertTrue($result['ok']);
        $this->pmssAssertArraySubsetSame(['actions' => ['install', 'configure', 'proftpd', 'fix', 'install'], 'time' => 4], $result);
    }

    public function testBusyLockAfterFixBrokenPreventsBaselineRetry(): void
    {
        $result = $this->fixture(['fix' => [124], 'fixRelease' => 1000]);
        $this->assertFalse($result['ok']);
        $this->pmssAssertArraySubsetSame(['actions' => ['install', 'configure', 'fix'], 'time' => 62], $result);
    }

    public function testFailedRetryReturnsFalseAndLogsReason(): void
    {
        $result = $this->fixture(['install' => [124, 100]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('still failing after retry', implode('\n', $result['logs']));
    }

    public function testStep2RecoveryConfiguresBeforeReapplyingBaseline(): void
    {
        $result = $this->fixture(['entry' => 'step2', 'install' => [0], 'busy' => 2]);
        $this->assertTrue($result['ok']);
        $this->pmssAssertArraySubsetSame(['actions' => ['configure', 'fix', 'reapply', 'install'], 'time' => 2], $result);
    }

    public function testStep2BusyLockSkipsFixBrokenAndReapplyAndReportsFailure(): void
    {
        $result = $this->fixture(['entry' => 'step2', 'busy' => 1000]);
        $this->assertFalse($result['ok']);
        $this->pmssAssertArraySubsetSame(['actions' => [], 'time' => 60], $result);
        $this->assertSame('dpkg_baseline', $result['json'][0]['reason']);
    }

    public function testWaitBudgetClampsFinalSleepAndChecksAllLockPaths(): void
    {
        foreach ([0, 1, 7] as $budget) {
            $result = $this->fixture(['entry' => 'wait', 'busy' => 1000, 'budget' => $budget]);
            $this->assertFalse($result['ok']);
            $this->assertSame($budget, $result['time']);
        }
        foreach (['/var/lib/dpkg/lock', '/var/lib/dpkg/lock-frontend', '/var/lib/apt/lists/lock', '/var/cache/apt/archives/lock'] as $lock) {
            $result = $this->fixture(['entry' => 'wait', 'busy' => 2, 'lock' => $lock]);
            $this->assertTrue($result['ok']);
            $this->assertSame(5, $result['time']);
        }
    }

    public function testMissingFuserRetainsWarningFallback(): void
    {
        $result = $this->fixture(['entry' => 'wait', 'fuser' => false]);
        $this->assertTrue($result['ok']);
        $this->pmssAssertArraySubsetSame(['sleeps' => [], 'logs' => ['[WARN] dist-upgrade: fuser not available; skipping dpkg lock checks']], $result);
    }

    private function fixture(array $options): array
    {
        $script = <<<'PHP'
namespace DpkgRecoveryFixture;
function time() { return $GLOBALS['clock']; }
function sleep($seconds) { $GLOBALS['sleeps'][] = $seconds; $GLOBALS['clock'] += $seconds; }
function pmssCommandPath($name) { return $GLOBALS['options']['fuser'] ? '/fixture/fuser' : ''; }
function exec($command, &$output, &$rc) {
    if (strpos($command, 'fuser ') !== 0) throw new \RuntimeException('Unexpected command');
    $rc = strpos($command, "'".$GLOBALS['options']['lock']."'") !== false && time() < $GLOBALS['busy'] ? 0 : 1;
}
function logMessage($message) { $GLOBALS['logs'][] = $message; }
function logmsg($message) {
    if (($GLOBALS['options']['throwAt'] ?? '') === $message) throw $GLOBALS['failure'];
    logMessage($message);
}
function pmssLogJson($row) { $GLOBALS['json'][] = $row; }
function aptCmd($args) { return 'apt-get '.$args; }
function dpkgCmd($args) { return 'dpkg '.$args; }
function pmssAptDpkgEnvPrefix() { return ''; }
function pmssSystemdRuntimeAvailable() { return $GLOBALS['options']['systemd'] ?? false; }
function pmssSystemdUnitState($verb, $unit) { return $GLOBALS['options']['unit']; }
function runCommand($command) { $GLOBALS['trace'][] = ['command', $command]; return 0; }
function shell_exec($command) { $GLOBALS['trace'][] = ['fuse-query']; return !empty($GLOBALS['options']['fuse']) ? 'install ok installed' : ''; }
function file($path, $flags) { $GLOBALS['trace'][] = ['read', $path]; return $GLOBALS['options']['lines']; }
function unlink($path) { $GLOBALS['trace'][] = ['unlink', $path]; $GLOBALS['cleaned'] = true; }
function pmssSelectDpkgSelectionsBaseline($version) { return $GLOBALS['options']['baseline']; }
function pmssDistroVersionFromEnv($version) { return $version; }
function pmssDpkgSelectionsSanitise($lines, $version) {
    $GLOBALS['trace'][] = ['sanitize', $lines, $version];
    return ['sanitised' => $GLOBALS['options']['sanitised'] ?? $lines, 'warnings' => $GLOBALS['options']['warnings'] ?? false];
}
function pmssWriteSanitisedDpkgSelectionsTempFile($lines) { $GLOBALS['trace'][] = ['stage', $lines]; return $GLOBALS['options']['stage']; }
function pmssDpkgSelectionsLogSummary($plan) {
    $GLOBALS['trace'][] = ['summary'];
    if (($GLOBALS['options']['throwAt'] ?? '') === 'summary') throw $GLOBALS['failure'];
}
function pmssLogStatus($level, $message, $rc) { $GLOBALS['trace'][] = [$level, $message, $rc]; }
function pmssRunProfiledCallable($description, $callback, $args) {
    if ($callback !== 'pmssApplyDpkgSelections') throw new \RuntimeException('Unexpected callback');
    $GLOBALS['actions'][] = 'reapply';
    return pmssApplyDpkgSelections(...$args);
}
function runStep($description, $command) {
    $GLOBALS['trace'][] = [$description, $command];
    if (($GLOBALS['options']['throwAt'] ?? '') === $description) throw $GLOBALS['failure'];
    if ($description === 'Applying dpkg selection baseline') return $GLOBALS['options']['setRc'] ?? 0;
    $action = strpos($command, 'dselect-upgrade') !== false ? 'install'
        : (strpos($command, '--fix-broken') !== false ? 'fix'
        : (strpos($command, '--configure -a') !== false ? 'configure'
        : (strpos($command, '--configure proftpd') !== false ? 'proftpd' : '')));
    if ($action === '') return 0; // availability merge and set-selections are also inert
    if (time() < $GLOBALS['busy']) throw new \RuntimeException('Package writer raced a dying lock owner');
    $GLOBALS['actions'][] = $action;
    $rc = empty($GLOBALS['options'][$action]) ? 0 : array_shift($GLOBALS['options'][$action]);
    if ($rc !== 0) $GLOBALS['busy'] = time() + ($GLOBALS['options'][$action.'Release'] ?? $GLOBALS['options']['release']);
    return $rc;
}
PHP;
        $script .= '$GLOBALS["options"] = '.var_export($options + ['entry' => 'baseline', 'install' => [124, 0],
            'release' => 2, 'busy' => 0, 'budget' => 7, 'lock' => '/var/lib/dpkg/lock', 'fuser' => true,
            'baseline' => '/fixture/selections', 'stage' => '/fixture/staged', 'lines' => ['alpha install']], true).';';
        foreach (['dpkgLocks.php', 'dpkgRecovery.php', 'dpkgBaselineApply.php', 'environment.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/'.$module, 'DpkgRecoveryFixture');
        }
        $script .= '$GLOBALS["clock"] = 0; $GLOBALS["busy"] = $GLOBALS["options"]["busy"];'
            .'$GLOBALS["trace"] = $GLOBALS["actions"] = $GLOBALS["logs"] = $GLOBALS["json"] = $GLOBALS["sleeps"] = []; $GLOBALS["cleaned"] = false;';
        $script .= '$failureClass = $GLOBALS["options"]["throwClass"] ?? "RuntimeException";'
            .'$GLOBALS["failure"] = new $failureClass("application fault"); $sameFailure = null; $ok = null; try {';
        if (($options['entry'] ?? '') === 'step2') {
            // Execute the actual orchestrator recovery block without running step2's system setup.
            $source = $this->pmssReadRepoFile('scripts/util/update-step2.php');
            $start = strpos($source, 'if (!$dpkgBaselineOk) {');
            $end = strpos($source, '// Re-apply libssl3', $start);
            $script .= '$dpkgBaselineOk = false; $effectiveRepoVersion = 12;';
            $script .= 'eval('.var_export('namespace DpkgRecoveryFixture;'.substr($source, $start, $end - $start), true).'); $ok = $dpkgBaselineOk;';
        } else {
            $script .= '$ok = $GLOBALS["options"]["entry"] === "wait" ? pmssWaitForDpkgLocks($GLOBALS["options"]["budget"], 5) : pmssApplyDpkgSelections(12, true);';
        }
        $script .= '} catch (\\Throwable $caught) { if ($caught !== $GLOBALS["failure"]) throw $caught; $sameFailure = true; }'
            .'echo json_encode(["ok" => $ok, "sameFailure" => $sameFailure, "actions" => $GLOBALS["actions"], "logs" => $GLOBALS["logs"],'
            .'"json" => $GLOBALS["json"], "time" => $GLOBALS["clock"], "sleeps" => $GLOBALS["sleeps"], "cleaned" => $GLOBALS["cleaned"], "trace" => $GLOBALS["trace"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
