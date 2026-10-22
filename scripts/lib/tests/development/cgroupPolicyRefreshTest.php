<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/cgroupPolicyRefresh.php';

class cgroupPolicyRefreshTest extends TestCase
{
    public function testHasExplicitIoPolicyRequiresLatencyOrCostKnobs(): void
    {
        $this->assertFalse(\pmssCgroupRefreshHasExplicitIoPolicy(['ramMiB' => 512]));
        $this->assertTrue(\pmssCgroupRefreshHasExplicitIoPolicy(['ioLatencyMs' => 50]));
        $this->assertTrue(\pmssCgroupRefreshHasExplicitIoPolicy(['ioCostQos' => 'enable=1 ctrl=user']));
        $this->assertTrue(\pmssCgroupRefreshHasExplicitIoPolicy(['ioCostModel' => 'ctrl=user model=linear']));
    }

    public function testHasExplicitIoPolicyMatchesIopsMaxKnobs(): void
    {
        $this->assertTrue(\pmssCgroupRefreshHasExplicitIoPolicy(['IOReadIOPS' => '/home:200']));
        $this->assertTrue(\pmssCgroupRefreshHasExplicitIoPolicy(['IOWriteIOPS' => '/home:100']));
        $this->assertFalse(\pmssCgroupRefreshHasExplicitIoPolicy(['IOReadIOPS' => '']));
        $this->assertFalse(\pmssCgroupRefreshHasExplicitIoPolicy(['IOWriteIOPS' => '']));
    }

    public function testBuildCommandCarriesIoCostAndLatencyFlags(): void
    {
        $command = \pmssUserConfigCliBuildStoredCgroupApplyCommand('alice', [
            'ramMiB' => 1024,
            'CPUWeight' => 200,
            'ioLatencyMs' => 50,
            'ioCostQos' => 'enable=1 ctrl=user',
            'ioCostModel' => 'ctrl=user model=linear',
        ]);

        $this->assertTrue(is_string($command));
        $this->assertStringContainsAllStrings([
            "'/scripts/util/userConfigCgroup.php' 'alice' '--apply' '--memory-high=1024'",
            "'--cpu-weight=200'",
            "'--io-latency-ms=50'",
            "'--io-cost-qos=enable=1 ctrl=user'",
            "'--io-cost-model=ctrl=user model=linear'",
        ], $command);
    }

    public function testBuildCommandReturnsNullWithoutMemoryBaseline(): void
    {
        $this->assertSame(null, \pmssUserConfigCliBuildStoredCgroupApplyCommand('alice', ['ioLatencyMs' => 50]));
    }

    public function testRefreshRestoresBfqOnceAfterAllPolicyAttempts(): void
    {
        $payload = ['ramMiB' => 1024, 'ioLatencyMs' => 50];
        $writer = '/scripts/cron/cgroupBfqWeightApply.php';
        foreach ([
            [['alice' => $payload, 'bob' => $payload], []],
            [['alice' => $payload, 'bob' => $payload], ['alice' => 3]],
            [['alice' => ['ioLatencyMs' => 50], 'bob' => $payload], []],
            [['alice' => ['ramMiB' => 1024]], []],
            [[], []],
            [['alice' => $payload], [$writer => 2]],
        ] as $case) {
            list($payloads, $failures) = $case;
            // Keep account discovery, cache publication, and commands inside the fixture.
            $script = <<<'PHP'
namespace PmssCgroupRefreshFixture;
class UserConfigStore {
    public function get($user) { return $GLOBALS['payloads'][$user]; }
    public function applyFallbacks($user, $payload) { return $payload; }
}
function pmssListManagedUsers($path) { return array_keys($GLOBALS['payloads']); }
function pmssCgroupPolicyLoad() { return []; }
function pmssIoCeilingRefresh($policy) { return true; }
function pmssUserLog($user, $message) {}
function logmsg($message) {}
function runStep($description, $command) {
    $GLOBALS['commands'][] = $command;
    foreach ($GLOBALS['failures'] as $needle => $rc) {
        if (strpos($command, $needle) !== false) return $rc;
    }
    return 0;
}
PHP;
            $script .= '$GLOBALS["payloads"] = '.var_export($payloads, true).';'
                .'$GLOBALS["failures"] = '.var_export($failures, true).'; $GLOBALS["commands"] = [];'
                .$this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/cgroupPolicyRefresh.php', 'PmssCgroupRefreshFixture')
                .'$rc = pmssCgroupPolicyRefreshRun(); echo json_encode([$rc, $GLOBALS["commands"]]);';
            $expected = [];
            foreach ($payloads as $user => $stored) {
                if (\pmssCgroupRefreshHasExplicitIoPolicy($stored)
                    && ($command = \pmssUserConfigCliBuildStoredCgroupApplyCommand($user, $stored)) !== null) {
                    $expected[] = $command;
                }
            }
            $expected[] = $writer;
            $this->assertSame([0, $expected], $this->pmssRunInlinePhpJson($script));
        }
    }

    public function testUpdaterRestoresBfqAfterUserBatchBeforeSummaryHandling(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/update-step2.php', [
            'ordered' => [[
                'needles' => [
                    '$userMaintenanceSummary = pmssRunProfiledCallable(\'Updating all user environments\'',
                    "runStep('Restoring direct BFQ weights after user maintenance', '/scripts/cron/cgroupBfqWeightApply.php');",
                    'pmssUpdateStep2HandleUserMaintenanceSummary($userMaintenanceSummary);',
                    "runStep('Refreshing FTP configuration', '/scripts/util/ftpConfig.php');",
                    "pmssRunProfiledCallable('Ensuring weekly TRIM timer', 'pmssEnsureFstrimTimer');",
                    "runStep('Restoring direct BFQ weights before root cron restore', '/scripts/cron/cgroupBfqWeightApply.php');",
                    "runStep('Refreshing root cron configuration', '/scripts/util/setupRootCron.php');",
                ],
            ]],
        ]);
    }

    public function testRootCronSchedulesCgroupPolicyRefresh(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'etc/seedbox/config/root.cron',
            '/scripts/cron/cgroupPolicyRefresh.php',
            'root.cron should schedule cgroupPolicyRefresh.php'
        );
        $this->pmssAssertRepoFileSubstringCount(
            'etc/seedbox/config/root.cron',
            '/scripts/cron/cgroupPolicyRefresh.php',
            2,
            'Expected cgroupPolicyRefresh.php to run at reboot and periodically'
        );
    }
}
