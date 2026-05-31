<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/logging.php';

class JournaldLimitsTest extends TestCase
{
    private function gib(int $value): int
    {
        return $value * 1024 * 1024 * 1024;
    }

    public function testSmallRootUsesTwentyPercent(): void
    {
        $rootBytes = $this->gib(20);
        $policy = \pmssJournaldLimitsForRootBytes($rootBytes);
        $this->assertEquals($this->gib(4), $policy['system_max_use_bytes']);
    }

    public function testSmallRootMinTwoGiB(): void
    {
        $rootBytes = $this->gib(8);
        $policy = \pmssJournaldLimitsForRootBytes($rootBytes);
        $this->assertEquals($this->gib(2), $policy['system_max_use_bytes']);
    }

    public function testLargeRootUsesTwentyGiB(): void
    {
        $rootBytes = $this->gib(100);
        $policy = \pmssJournaldLimitsForRootBytes($rootBytes);
        $this->assertEquals($this->gib(20), $policy['system_max_use_bytes']);
    }

    public function testFiftyGiBBoundaryUsesFlatTwentyGiBCap(): void
    {
        $rootBytes = $this->gib(50);
        $policy = \pmssJournaldLimitsForRootBytes($rootBytes);
        $this->assertEquals($this->gib(20), $policy['system_max_use_bytes']);
    }

    public function testKeepFreeClampLow(): void
    {
        $rootBytes = $this->gib(8);
        $policy = \pmssJournaldLimitsForRootBytes($rootBytes);
        $this->assertEquals($this->gib(1), $policy['system_keep_free_bytes']);
    }

    public function testKeepFreeClampHigh(): void
    {
        $rootBytes = $this->gib(500);
        $policy = \pmssJournaldLimitsForRootBytes($rootBytes);
        $this->assertEquals($this->gib(10), $policy['system_keep_free_bytes']);
    }

    public function testRuntimeClampLow(): void
    {
        $rootBytes = $this->gib(8);
        $policy = \pmssJournaldLimitsForRootBytes($rootBytes);
        $this->assertEquals(256 * 1024 * 1024, $policy['runtime_max_use_bytes']);
    }

    public function testTemplateRenderAndWrite(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-journald-cfg-');
        $targetDir = $this->pmssMakeTempDir('pmss-journald-journald-');
        $template = $cfgDir.'/template.journald.conf.d-pmss-limits.conf';
        $tplBody = "[Journal]\n"
            ."SystemMaxUse=%%PMSS_JOURNALD_SYSTEM_MAX_USE%%\n"
            ."SystemKeepFree=%%PMSS_JOURNALD_SYSTEM_KEEP_FREE%%\n"
            ."RuntimeMaxUse=%%PMSS_JOURNALD_RUNTIME_MAX_USE%%\n"
            ."RateLimitIntervalSec=%%PMSS_JOURNALD_RATE_LIMIT_INTERVAL%%\n"
            ."RateLimitBurst=%%PMSS_JOURNALD_RATE_LIMIT_BURST%%\n";
        file_put_contents($template, $tplBody);

        $this->pmssWithEnv([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_JOURNALD_CONF_DIR' => $targetDir,
            'PMSS_ROOT_FS_BYTES' => (string) $this->gib(10),
        ], function (): void {
            \pmssApplyJournaldLimits();
        });

        $target = $targetDir.'/pmss-limits.conf';
        $this->pmssAssertFileContainsAllStrings($target, [
            'SystemMaxUse=2G', 'SystemKeepFree=1G', 'RuntimeMaxUse=256M',
            'RateLimitIntervalSec=10s', 'RateLimitBurst=2000',
        ], 'Journald limits file missing');
    }

    public function testDryRunSkipsJournaldRestartAfterWritingTemplate(): void
    {
        unset($GLOBALS['PMSS_PROFILE'], $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']);

        $cfgDir = $this->pmssMakeTempDir('pmss-journald-cfg-');
        $targetDir = $this->pmssMakeTempDir('pmss-journald-journald-');
        file_put_contents($cfgDir.'/template.journald.conf.d-pmss-limits.conf', "[Journal]\nSystemMaxUse=%%PMSS_JOURNALD_SYSTEM_MAX_USE%%\n");

        $this->pmssWithEnv([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_JOURNALD_CONF_DIR' => $targetDir,
            'PMSS_ROOT_FS_BYTES' => (string) $this->gib(10),
            'PMSS_DRY_RUN' => '1',
        ], function (): void {
            \pmssApplyJournaldLimits();
        });

        $target = $targetDir.'/pmss-limits.conf';
        $this->assertTrue(file_exists($target), 'Journald limits file missing in dry-run');
        $profile = $GLOBALS['PMSS_PROFILE'] ?? [];
        $last = end($profile);
        $this->assertEquals('SKIP', $last['status'] ?? '');
        $this->assertEquals('Restarting systemd-journald to apply log caps (test/dry-run)', $last['description'] ?? '');
        $this->assertEquals('', $last['command'] ?? '');
    }

    public function testJournaldLimitsRejectsSymlinkedTargetDir(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-journald-cfg-');
        $root = $this->pmssMakeTempDir('pmss-journald-root-');
        $realTargetDir = $root.'/real';
        $this->pmssEnsureDir($realTargetDir, 0755);
        $linkTargetDir = $root.'/journald-conf';
        $this->pmssCreateSymlinkOrSkip($realTargetDir, $linkTargetDir);
        file_put_contents($cfgDir.'/template.journald.conf.d-pmss-limits.conf', "[Journal]\nSystemMaxUse=%%PMSS_JOURNALD_SYSTEM_MAX_USE%%\n");

        $messages = [];
        $this->pmssWithEnv([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_JOURNALD_CONF_DIR' => $linkTargetDir,
            'PMSS_ROOT_FS_BYTES' => (string) $this->gib(10),
        ], function () use (&$messages): void {
            \pmssApplyJournaldLimits($this->pmssMakeArrayLogger($messages));
        });

        $this->assertTrue(!file_exists($realTargetDir.'/pmss-limits.conf'), 'must not write through symlinked journald dir');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unable to prepare journald config directory'), 'expected safe-directory warning');
    }
}
