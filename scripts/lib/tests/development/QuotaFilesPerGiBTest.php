<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/traffic.php';
require_once dirname(__DIR__, 2).'/user/resourcesListModel.php';
require_once dirname(__DIR__, 2).'/agentDiagnostics.php';

/** The per-host file quota ratio: parsing, limits, enforced command, panel value and diagnostics (ADR 0342). */
class QuotaFilesPerGiBTest extends TestCase
{
    private $configDir;
    private $stateDir;

    public function setUp(): void
    {
        $this->configDir = $this->pmssMakeTempDir('pmss-quota-files-config-');
        $this->stateDir = $this->pmssMakeTempDir('pmss-quota-files-state-');
        $this->pmssEnsureDir($this->configDir.'/users');
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $this->configDir, 'PMSS_STATE_DIR' => $this->stateDir], true);
    }

    private function ratioFile(?string $content): void
    {
        @unlink($this->configDir.'/quotaFilesPerGiB');
        if ($content !== null) {
            $this->pmssWriteFile($this->configDir.'/quotaFilesPerGiB', $content);
        }
    }

    public function testUnusableFileMeansTheHistoricalRatio(): void
    {
        foreach ([null, '', "\n", '0', '-5', 'abc', '2000x', '2000.5', '+5', '1048577', '99999999999999999999', "2000\n3000", '0500'] as $content) {
            $this->ratioFile($content);
            $this->assertSame(500, \pmssQuotaFilesPerGiB(), var_export($content, true));
        }
        // A directory or a symlink in place of the file is never read.
        $this->ratioFile(null);
        $target = $this->pmssWriteFile($this->configDir.'/target', '9000');
        $this->assertTrue(symlink($target, $this->configDir.'/quotaFilesPerGiB'));
        $this->assertSame(500, \pmssQuotaFilesPerGiB());
        unlink($this->configDir.'/quotaFilesPerGiB');
        mkdir($this->configDir.'/quotaFilesPerGiB');
        $this->assertSame(500, \pmssQuotaFilesPerGiB());
        rmdir($this->configDir.'/quotaFilesPerGiB');
    }

    public function testUsableFileSetsTheRatio(): void
    {
        foreach (['2000' => 2000, "2000\n" => 2000, ' 4000 ' => 4000, '1' => 1, '1048576' => 1048576] as $content => $expected) {
            $this->ratioFile((string) $content);
            $this->assertSame($expected, \pmssQuotaFilesPerGiB(), var_export($content, true));
        }
    }

    public function testLimitsKeepTheFloorAndBurst(): void
    {
        $this->ratioFile(null);
        $this->assertSame(['soft' => 50000, 'hard' => 62500], \pmssQuotaFilesLimit(100));
        $this->assertSame(['soft' => 15000, 'hard' => 18750], \pmssQuotaFilesLimit(5));
        $this->ratioFile('2000');
        $this->assertSame(['soft' => 200000, 'hard' => 250000], \pmssQuotaFilesLimit(100));
        $this->assertSame(['soft' => 15000, 'hard' => 18750], \pmssQuotaFilesLimit(5));
        $this->assertSame(['soft' => 15000, 'hard' => 18750], \pmssQuotaFilesLimit(30, 500));
        $this->assertSame(['soft' => 15000, 'hard' => 18750], \pmssQuotaFilesLimit(0));
    }

    public function testEnforcedCommandAndPanelValueUseTheSameLimits(): void
    {
        $this->pmssMakeTrackedHomeRoot('pmss-quota-files-home-');
        $this->pmssTrackEnvOverrides(['PMSS_DRY_RUN' => '1'], true);
        foreach ([null => [50000, 62500], '2000' => [200000, 250000]] as $content => $expected) {
            $this->ratioFile($content === '' ? null : (string) $content);
            $this->pmssResetRuntimeProfile();
            $this->assertTrue(\userApplyDiskQuota(['name' => 'fixtur01', 'quota' => 100]) === true);
            $this->assertStringContainsString(
                "setquota 'fixtur01' 104857600 131072000 {$expected[0]} {$expected[1]} -a",
                implode("\n", $this->pmssProfileCommands())
            );
            $state = \pmssUserResourcesListQuotaState(['quota' => 100], false);
            $this->assertSame($expected, [$state['inode_quota'], $state['inode_burst']]);
        }
        $this->assertTrue(\userApplyDiskQuota(['name' => '../evil', 'quota' => 100]) === false);
    }

    /** Run one diagnostics probe with its fixed host paths pointed at the fixture. */
    private function probe(string $name): string
    {
        $spec = \pmssAgentDiagnosticsSectionSpecs()['storage'][$name];
        $spec['command'] = str_replace(
            ['/scripts/lib/user/quotaFilesPerGiB.php', '/etc/seedbox/config/quotaFilesPerGiB'],
            [dirname(__DIR__, 2).'/user/quotaFilesPerGiB.php', $this->configDir.'/quotaFilesPerGiB'],
            $spec['command']
        );
        return \pmssAgentDiagnosticsSpecCollect($spec);
    }

    public function testDiagnosticsReportTheRatioAndFilePresence(): void
    {
        foreach ([[null, '500', 'no'], ['2000', '2000', 'yes'], ['2000x', '500', 'yes']] as [$content, $ratio, $present]) {
            $this->ratioFile($content);
            $this->assertSame([$ratio, $present], [$this->probe('quota_files_per_gib'), $this->probe('quota_files_per_gib_file_present')]);
        }
    }

    public function testNoSourceKeepsTheOldConstant(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/traffic.php', ['pmssQuotaFilesLimit('], ['filesLimitPerGb', '* 500']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/resourcesListModel.php', ['pmssQuotaFilesLimit($diskQuotaGiB)'], ['* 500', '15000']);
    }
}
