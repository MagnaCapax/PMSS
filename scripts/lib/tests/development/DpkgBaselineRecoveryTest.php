<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise real recovery control flow with every package command and clock stubbed. */
class DpkgBaselineRecoveryTest extends TestCase
{
    public function testTimeoutAndOrdinaryFailureConfigureDpkgBeforeFixBrokenAndRetry(): void
    {
        foreach ([124, 100, 137] as $rc) {
            $result = $this->fixture(['install' => [$rc, 0]]);
            $this->assertTrue($result['ok']);
            $this->assertSame(['install', 'configure', 'fix', 'install'], $result['actions']);
            $this->assertSame(2, $result['time']);
            $this->assertStringContainsString('dpkg lock cleared', implode('\n', $result['logs']));
        }
    }

    public function testSuccessfulBaselineNeedsNoRecovery(): void
    {
        $result = $this->fixture(['install' => [0]]);
        $this->assertTrue($result['ok']);
        $this->assertSame(['install'], $result['actions']);
        $this->assertSame([], $result['sleeps']);
    }

    public function testFailedFixBrokenCompletesDpkgAgainBeforeRetry(): void
    {
        foreach ([100, 124] as $rc) {
            $result = $this->fixture(['fix' => [$rc]]);
            $this->assertTrue($result['ok']);
            $this->assertSame(['install', 'configure', 'fix', 'configure', 'install'], $result['actions']);
            $this->assertSame(4, $result['time']);
        }
    }

    public function testBusyLockStopsRecoveryWithinSixtySeconds(): void
    {
        $result = $this->fixture(['release' => 1000]);
        $this->assertFalse($result['ok']);
        $this->assertSame(['install'], $result['actions']);
        $this->assertSame(60, $result['time']);
        $this->assertStringContainsString('did not clear within 60 seconds', implode('\n', $result['logs']));
        $this->assertTrue($result['cleaned'], 'staged selections must be cleaned even when locks stay busy');
    }

    public function testConfigureTimeoutWaitsBeforeProftpdFallbackAndApt(): void
    {
        $result = $this->fixture(['configure' => [124]]);
        $this->assertTrue($result['ok']);
        $this->assertSame(['install', 'configure', 'proftpd', 'fix', 'install'], $result['actions']);
        $this->assertSame(4, $result['time']);
    }

    public function testBusyLockAfterFixBrokenPreventsBaselineRetry(): void
    {
        $result = $this->fixture(['fix' => [124], 'fixRelease' => 1000]);
        $this->assertFalse($result['ok']);
        $this->assertSame(['install', 'configure', 'fix'], $result['actions']);
        $this->assertSame(62, $result['time']);
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
        $this->assertSame(['configure', 'fix', 'reapply', 'install'], $result['actions']);
        $this->assertSame(2, $result['time']);
    }

    public function testStep2BusyLockSkipsFixBrokenAndReapplyAndReportsFailure(): void
    {
        $result = $this->fixture(['entry' => 'step2', 'busy' => 1000]);
        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['actions']);
        $this->assertSame(60, $result['time']);
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
        $this->assertSame([], $result['sleeps']);
        $this->assertSame(['[WARN] dist-upgrade: fuser not available; skipping dpkg lock checks'], $result['logs']);
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
function logmsg($message) { logMessage($message); }
function pmssLogJson($row) { $GLOBALS['json'][] = $row; }
function aptCmd($args) { return 'apt-get '.$args; }
function dpkgCmd($args) { return 'dpkg '.$args; }
function pmssAptDpkgEnvPrefix() { return ''; }
function pmssSystemdRuntimeAvailable() { return false; }
function shell_exec($command) { return ''; }
function file($path, $flags) { return ['alpha install']; }
function unlink($path) { $GLOBALS['cleaned'] = true; }
function pmssSelectDpkgSelectionsBaseline($version) { return '/fixture/selections'; }
function pmssDpkgSelectionsSanitise($lines, $version) { return ['sanitised' => $lines, 'warnings' => false]; }
function pmssWriteSanitisedDpkgSelectionsTempFile($lines) { return '/fixture/staged'; }
function pmssDpkgSelectionsLogSummary($plan) {}
function pmssRunProfiledCallable($description, $callback, $args) {
    if ($callback !== 'pmssApplyDpkgSelections') throw new \RuntimeException('Unexpected callback');
    $GLOBALS['actions'][] = 'reapply';
    return pmssApplyDpkgSelections(...$args);
}
function runStep($description, $command) {
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
            'release' => 2, 'busy' => 0, 'budget' => 7, 'lock' => '/var/lib/dpkg/lock', 'fuser' => true], true).';';
        foreach (['dpkgLocks.php', 'dpkgRecovery.php', 'environment.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/'.$module, 'DpkgRecoveryFixture');
        }
        $script .= '$GLOBALS["clock"] = 0; $GLOBALS["busy"] = $GLOBALS["options"]["busy"];'
            .'$GLOBALS["actions"] = $GLOBALS["logs"] = $GLOBALS["json"] = $GLOBALS["sleeps"] = []; $GLOBALS["cleaned"] = false;';
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
        $script .= 'echo json_encode(["ok" => $ok, "actions" => $GLOBALS["actions"], "logs" => $GLOBALS["logs"],'
            .'"json" => $GLOBALS["json"], "time" => $GLOBALS["clock"], "sleeps" => $GLOBALS["sleeps"], "cleaned" => $GLOBALS["cleaned"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
