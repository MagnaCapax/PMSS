<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class UserConfigLighttpdLogicTest extends TestCase
{
    public function testMemoryClampBounds(): void
    {
        $this->assertEquals(125, \pmssClampMemoryLimit(50));
        $this->assertEquals(512, \pmssClampMemoryLimit(512));
        $this->assertEquals(1024, \pmssClampMemoryLimit(5000));
    }

    public function testProcessPlanFollowsCpuQuota(): void
    {
        $plan = \pmssComputePhpProcessPlan(100);
        $this->assertEquals(1, $plan['max_procs']);
        $this->assertEquals(6, $plan['children']);
        $this->assertEquals(6, $plan['totalThreads']);

        $planHigh = \pmssComputePhpProcessPlan(250);
        $this->assertEquals(2, $planHigh['max_procs']);
        $this->assertEquals(12, $planHigh['totalThreads']);
    }

    public function testProcessPlanCapsHighQuotaAtEightPhpProcessGroups(): void
    {
        $plan = \pmssComputePhpProcessPlan(1200);

        $this->assertEquals(8, $plan['max_procs']);
        $this->assertEquals(6, $plan['children']);
        $this->assertEquals(48, $plan['totalThreads']);
    }

    public function testCpuQuotaLegacy85UsesThreadBasedDefault(): void
    {
        // Force a deterministic CPU thread count for the calculation.
        putenv('PMSS_TOTAL_CPU_THREADS=8');
        $props = ['CPUQuota' => '85%'];
        $policy = ['cpuQuotaPercent' => 85];

        $quota = \pmssExtractCpuQuotaPercent($props, $policy);
        // 8 logical threads × 85% = 680% effective quota.
        $this->assertEquals(680, $quota);
    }

    public function testCpuQuotaExplicitValuePreservedWhenNonLegacy(): void
    {
        putenv('PMSS_TOTAL_CPU_THREADS=8');
        $props = ['CPUQuota' => '250%'];
        $policy = ['cpuQuotaPercent' => 85];

        $quota = \pmssExtractCpuQuotaPercent($props, $policy);
        $this->assertEquals(250, $quota);
    }

    public function testCpuQuotaDerivesFromPeriodValuesWhenDirectQuotaMissing(): void
    {
        putenv('PMSS_TOTAL_CPU_THREADS=8');
        $props = [
            'CPUQuotaPerSecUSec' => '50000',
            'CPUQuotaPeriodUSec' => '100000',
        ];
        $policy = ['cpuQuotaPercent' => 85];

        $quota = \pmssExtractCpuQuotaPercent($props, $policy);
        $this->assertEquals(50, $quota);
    }

    public function testCpuQuotaFallsBackToThreadsWhenMissing(): void
    {
        putenv('PMSS_TOTAL_CPU_THREADS=4');
        $props = [];
        $policy = [];

        $quota = \pmssExtractCpuQuotaPercent($props, $policy);
        // 4 logical threads × 85% = 340% effective quota.
        $this->assertEquals(340, $quota);
    }

    public function testParseSizeToMiB(): void
    {
        $this->assertEquals(500, \pmssParseSizeToMiB('524288000'));
        $this->assertEquals(500, \pmssParseSizeToMiB('500M'));
        $this->assertEquals(1, \pmssParseSizeToMiB('1024K'));
    }

    public function testClampLighttpdBandwidthLimits(): void
    {
        $template = "connection.kbytes-per-second = 204800\nserver.kbytes-per-second = 409600\n";
        $clamped = \pmssClampLighttpdBandwidthLimits($template);
        $this->assertStringContainsAllStrings([
            'connection.kbytes-per-second = 0',
            'server.kbytes-per-second = 0',
        ], $clamped);

        $templateOk = "connection.kbytes-per-second = 1024\nserver.kbytes-per-second = 65535\n";
        $clampedOk = \pmssClampLighttpdBandwidthLimits($templateOk);
        $this->assertStringContainsAllStrings([
            'connection.kbytes-per-second = 1024',
            'server.kbytes-per-second = 65535',
        ], $clampedOk);

        $templateComment = "server.kbytes-per-second = 2048 # keep\n";
        $clampedComment = \pmssClampLighttpdBandwidthLimits($templateComment);
        $this->assertStringContainsString('server.kbytes-per-second = 2048 # keep', $clampedComment);
    }

    public function testShouldConfigureLighttpdSkipsNonExistingHome(): void
    {
        $this->assertFalse(\pmssShouldConfigureLighttpdForHome('/tmp/pmss-nonexistent-home'));
    }

    public function testShouldConfigureLighttpdSkipsSuspendedUsers(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-lighttpd-suspended-', 'www-disabled', 'user');

        $this->assertFalse(\pmssShouldConfigureLighttpdForHome($home));
    }

    public function testShouldConfigureLighttpdSkipsMissingWebRoot(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-lighttpd-missing-www-', '', 'user');

        $this->assertFalse(\pmssShouldConfigureLighttpdForHome($home));
    }

    public function testShouldConfigureLighttpdRequiresRtorrentConfig(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-lighttpd-rtorrent-', 'www', 'user');

        $this->assertFalse(\pmssShouldConfigureLighttpdForHome($home));

        // Add .rtorrent.rc and expect the helper to allow configuration.
        file_put_contents($home.'/.rtorrent.rc', "dummy");
        $this->assertTrue(\pmssShouldConfigureLighttpdForHome($home));
    }

    public function testStripLighttpdWebdavConfigRemovesManagedBlock(): void
    {
        $template = <<<'LIGHTTPD'
server.modules = (
  "mod_access",
  "mod_webdav",
)

# PMSS_WEBDAV_BEGIN
$HTTP["url"] =~ "^/webdav-user($|/)" {
    webdav.activate = "enable"
    webdav.sqlite-db-name = "/home/user/.lighttpd/webdav.lock.db"
}
# PMSS_WEBDAV_END
LIGHTTPD;

        $stripped = \pmssStripLighttpdWebdavConfig($template);
        $this->pmssAssertStringNotContainsString('webdav.activate', $stripped);
        $this->pmssAssertStringNotContainsString('PMSS_WEBDAV_BEGIN', $stripped);
        $this->assertStringContainsAllStrings(['mod_webdav', '#"mod_webdav",'], $stripped);
    }

    public function testAtomicWriteFileRejectsSymlinkTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-lighttpd-write-');
        $realPath = $root.'/real.conf';
        $linkPath = $root.'/link.conf';
        file_put_contents($realPath, 'original');
        symlink($realPath, $linkPath);

        $this->assertFalse(\pmssAtomicWriteFile($linkPath, 'updated'));
        $this->assertEquals('original', file_get_contents($realPath));
    }

    public function testWriteUserFileWritesContentAndMode(): void
    {
        $root = $this->pmssMakeTempDir('pmss-lighttpd-write-');
        $path = $root.'/custom.conf';
        $owner = $this->pmssCurrentOwner();

        $this->assertTrue(\pmssWriteUserFile($path, 'server.modules = ()', $owner, 0640));
        $this->assertEquals('server.modules = ()', file_get_contents($path));
        $this->assertEquals(0640, fileperms($path) & 0777);
    }

    public function testProxyPortEnsureRejectsSymlinkTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-lighttpd-proxy-port-');
        $realPath = $root.'/real-port';
        $linkPath = $root.'/rclone-port';
        file_put_contents($realPath, '1234');
        symlink($realPath, $linkPath);

        $ports = \pmssLighttpdProxyPortsEnsure(['rclone' => $linkPath]);

        $this->assertEquals(0, $ports['rclone']);
        $this->assertEquals('1234', file_get_contents($realPath));
    }

    public function testProxyPortEnsurePersistsMissingPortInsideRange(): void
    {
        $root = $this->pmssMakeTempDir('pmss-lighttpd-proxy-port-');
        $path = $root.'/rclone-port';

        $ports = \pmssLighttpdProxyPortsEnsure(['rclone' => $path]);

        $this->assertTrue(\pmssNetworkPortInRange($ports['rclone'], 1024, 65500));
        $this->assertEquals((string) $ports['rclone'], file_get_contents($path));
    }

    public function testUserConfigEntryPointKeepsLighttpdApplyHelperWiring(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/userConfigLighttpd.php');

        $this->assertStringContainsAllStrings([
            "require_once dirname(__DIR__).'/lib/lighttpd/userConfigApply.php';",
        ], $src);
        foreach ([
            "require_once dirname(__DIR__).'/lib/lighttpd/delugeWebConf.php';",
            "require_once dirname(__DIR__).'/lib/lighttpd/proxyFragments.php';",
            "require_once dirname(__DIR__).'/lib/lighttpd/resourcePlan.php';",
            "require_once dirname(__DIR__).'/lib/lighttpd/userDirectoriesPrepare.php';",
            "require_once dirname(__DIR__).'/lib/lighttpd/configRender.php';",
        ] as $forbidden) {
            $this->pmssAssertStringNotContainsString($forbidden, $src);
        }
    }

    public function testPhpIniContentRendererKeepsExistingDirectivesStable(): void
    {
        $updated = \pmssLighttpdApplyPhpIniContent(
            "engine = On\nmemory_limit = 64M\n; upload_tmp_dir = /tmp\n",
            'alice',
            512
        );

        $this->assertSame(
            "engine = On\nmemory_limit = 512M\nupload_tmp_dir = /home/alice/.lighttpd/upload\n",
            $updated
        );
    }

    public function testLighttpdTemplatePassesPerUserPhpIniInBinPath(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');
        $rendered = \pmssLighttpdRenderUserConfig(
            $template,
            'alice',
            31234,
            0,
            0,
            ['maxProcs' => 2, 'children' => 6]
        );

        $this->assertStringContainsString(
            '"bin-path"              => "/usr/bin/php-cgi -c /home/alice/.lighttpd/php.ini",',
            $rendered
        );
        $this->pmssAssertStringNotContainsString('"bin-args"', $rendered);
    }

    public function testPhpIniContentRendererAppendsMissingDirectives(): void
    {
        $updated = \pmssLighttpdApplyPhpIniContent("engine = On\n", 'bob', 256);

        $this->assertSame(
            "engine = On\nmemory_limit = 256M\nupload_tmp_dir = /home/bob/.lighttpd/upload\n",
            $updated
        );
    }

    public function testUserConfigApplyFacadeLoadsFocusedHelpers(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/lighttpd/userConfigApply.php');

        $this->assertStringContainsAllStrings([
            "require_once __DIR__.'/configRender.php';",
            "require_once __DIR__.'/delugeWebConf.php';",
            "require_once __DIR__.'/proxyFragments.php';",
            "require_once __DIR__.'/resourcePlan.php';",
            "require_once __DIR__.'/userDirectoriesPrepare.php';",
            'function pmssUserConfigLighttpdConfigureUser(',
        ], $src);
        foreach ([
            'pmssClampLighttpdBandwidthLimits',
            'pmssStripLighttpdWebdavConfig',
            'pmssParseSizeToMiB',
            'pmssComputePhpProcessPlan',
            'pmssShouldConfigureLighttpdForHome',
            'pmssLighttpdWatchdogSocketPaths',
            'pmssEnsureWebdavLockDatabase',
            'pmssDelugeSessionsListDetected',
            'pmssDelugeNormalizeEmptySessionsObject',
            'pmssDelugeReadWebConf',
            'pmssDelugeWriteWebConf',
            'pmssLighttpdManagedProxyFragment',
        ] as $functionName) {
            $this->assertTrue(function_exists($functionName), $functionName.' should be loaded through the facade');
        }

        $this->assertSame(
            0,
            preg_match_all('/function\s+pmss[A-Za-z]+LighttpdProxyFragment\(/', $src),
            'Legacy per-service proxy fragment wrappers should stay removed'
        );
    }
}
