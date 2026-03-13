<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/journald.php';

class JournaldLimitsTest extends TestCase
{
    private function gib(int $value): int
    {
        return $value * 1024 * 1024 * 1024;
    }

    private function mib(int $value): int
    {
        return $value * 1024 * 1024;
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/pmss-journald-'.bin2hex(random_bytes(4)).'-'.$prefix;
        @mkdir($dir, 0700, true);
        return $dir;
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
        $this->assertEquals($this->mib(256), $policy['runtime_max_use_bytes']);
    }

    public function testRootFilesystemBytesUsesEnvOverride(): void
    {
        $previous = getenv('PMSS_ROOT_FS_BYTES');
        putenv('PMSS_ROOT_FS_BYTES=123456');

        try {
            $this->assertEquals(123456, \pmssJournaldRootFilesystemBytes());
        } finally {
            if ($previous === false) {
                putenv('PMSS_ROOT_FS_BYTES');
            } else {
                putenv('PMSS_ROOT_FS_BYTES='.$previous);
            }
        }
    }

    public function testTemplateRenderAndWrite(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $targetDir = $this->tempDir('journald');
        $template = $cfgDir.'/template.journald.conf.d-pmss-limits.conf';
        $tplBody = "[Journal]\n"
            ."SystemMaxUse=%%PMSS_JOURNALD_SYSTEM_MAX_USE%%\n"
            ."SystemKeepFree=%%PMSS_JOURNALD_SYSTEM_KEEP_FREE%%\n"
            ."RuntimeMaxUse=%%PMSS_JOURNALD_RUNTIME_MAX_USE%%\n"
            ."RateLimitIntervalSec=%%PMSS_JOURNALD_RATE_LIMIT_INTERVAL%%\n"
            ."RateLimitBurst=%%PMSS_JOURNALD_RATE_LIMIT_BURST%%\n";
        file_put_contents($template, $tplBody);

        $prevConfig = getenv('PMSS_CONFIG_DIR');
        $prevJournald = getenv('PMSS_JOURNALD_CONF_DIR');
        $prevRoot = getenv('PMSS_ROOT_FS_BYTES');

        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_JOURNALD_CONF_DIR='.$targetDir);
        putenv('PMSS_ROOT_FS_BYTES='.(string)$this->gib(10));

        try {
            \pmssApplyJournaldLimits();
        } finally {
            if ($prevConfig === false) { putenv('PMSS_CONFIG_DIR'); } else { putenv('PMSS_CONFIG_DIR='.$prevConfig); }
            if ($prevJournald === false) { putenv('PMSS_JOURNALD_CONF_DIR'); } else { putenv('PMSS_JOURNALD_CONF_DIR='.$prevJournald); }
            if ($prevRoot === false) { putenv('PMSS_ROOT_FS_BYTES'); } else { putenv('PMSS_ROOT_FS_BYTES='.$prevRoot); }
        }

        $target = $targetDir.'/pmss-limits.conf';
        $this->assertTrue(file_exists($target), 'Journald limits file missing');
        $data = (string)file_get_contents($target);
        $this->assertStringContainsString('SystemMaxUse=2G', $data);
        $this->assertStringContainsString('SystemKeepFree=1G', $data);
        $this->assertStringContainsString('RuntimeMaxUse=256M', $data);
        $this->assertStringContainsString('RateLimitIntervalSec=10s', $data);
        $this->assertStringContainsString('RateLimitBurst=2000', $data);
    }
}
