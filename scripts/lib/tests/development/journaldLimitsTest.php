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

    private function withEnv(array $values, callable $callback): void
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($value === null ? $key : $key.'='.$value);
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
        }
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
        $this->assertEquals($this->mib(256), $policy['runtime_max_use_bytes']);
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

        $this->withEnv([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_JOURNALD_CONF_DIR' => $targetDir,
            'PMSS_ROOT_FS_BYTES' => (string) $this->gib(10),
        ], function (): void {
            \pmssApplyJournaldLimits();
        });

        $target = $targetDir.'/pmss-limits.conf';
        $this->assertTrue(file_exists($target), 'Journald limits file missing');
        $data = (string)file_get_contents($target);
        $this->assertStringContainsString('SystemMaxUse=2G', $data);
        $this->assertStringContainsString('SystemKeepFree=1G', $data);
        $this->assertStringContainsString('RuntimeMaxUse=256M', $data);
        $this->assertStringContainsString('RateLimitIntervalSec=10s', $data);
        $this->assertStringContainsString('RateLimitBurst=2000', $data);
    }

    public function testDryRunSkipsJournaldRestartAfterWritingTemplate(): void
    {
        unset($GLOBALS['PMSS_PROFILE'], $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']);

        $cfgDir = $this->tempDir('cfg');
        $targetDir = $this->tempDir('journald');
        file_put_contents($cfgDir.'/template.journald.conf.d-pmss-limits.conf', "[Journal]\nSystemMaxUse=%%PMSS_JOURNALD_SYSTEM_MAX_USE%%\n");

        $this->withEnv([
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
}
