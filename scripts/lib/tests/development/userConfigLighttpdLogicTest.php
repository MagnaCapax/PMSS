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
        $this->assertEquals(2, $plan['max_procs']);
        $this->assertEquals(2, $plan['children']);
        $this->assertEquals(4, $plan['totalThreads']);

        $planHigh = \pmssComputePhpProcessPlan(250);
        $this->assertEquals(5, $planHigh['max_procs']);
        $this->assertEquals(10, $planHigh['totalThreads']);
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
        $this->assertTrue(strpos($clamped, 'connection.kbytes-per-second = 0') !== false);
        $this->assertTrue(strpos($clamped, 'server.kbytes-per-second = 0') !== false);

        $templateOk = "connection.kbytes-per-second = 1024\nserver.kbytes-per-second = 65535\n";
        $clampedOk = \pmssClampLighttpdBandwidthLimits($templateOk);
        $this->assertTrue(strpos($clampedOk, 'connection.kbytes-per-second = 1024') !== false);
        $this->assertTrue(strpos($clampedOk, 'server.kbytes-per-second = 65535') !== false);

        $templateComment = "server.kbytes-per-second = 2048 # keep\n";
        $clampedComment = \pmssClampLighttpdBandwidthLimits($templateComment);
        $this->assertTrue(strpos($clampedComment, 'server.kbytes-per-second = 2048 # keep') !== false);
    }

    public function testShouldConfigureLighttpdSkipsNonExistingHome(): void
    {
        $this->assertTrue(!\pmssShouldConfigureLighttpdForHome('/tmp/pmss-nonexistent-home'));
    }

    public function testShouldConfigureLighttpdSkipsSuspendedUsers(): void
    {
        $base = sys_get_temp_dir().'/pmss-lighttpd-suspended-'.uniqid('', true);
        $home = $base.'/user';
        @mkdir($home.'/www-disabled', 0755, true);

        $this->assertTrue(!\pmssShouldConfigureLighttpdForHome($home));
    }

    public function testShouldConfigureLighttpdSkipsMissingWebRoot(): void
    {
        $base = sys_get_temp_dir().'/pmss-lighttpd-missing-www-'.uniqid('', true);
        $home = $base.'/user';
        @mkdir($home, 0755, true);

        $this->assertTrue(!\pmssShouldConfigureLighttpdForHome($home));
    }

    public function testShouldConfigureLighttpdRequiresRtorrentConfig(): void
    {
        $base = sys_get_temp_dir().'/pmss-lighttpd-rtorrent-'.uniqid('', true);
        $home = $base.'/user';
        @mkdir($home.'/www', 0755, true);

        $this->assertTrue(!\pmssShouldConfigureLighttpdForHome($home));

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
        $this->assertTrue(strpos($stripped, 'webdav.activate') === false);
        $this->assertTrue(strpos($stripped, 'PMSS_WEBDAV_BEGIN') === false);
        $this->assertTrue(strpos($stripped, 'mod_webdav') !== false);
        $this->assertTrue(strpos($stripped, '#"mod_webdav",') !== false);
    }

    public function testAtomicWriteFileRejectsSymlinkTarget(): void
    {
        $root = sys_get_temp_dir().'/pmss-lighttpd-write-'.uniqid('', true);
        $realPath = $root.'/real.conf';
        $linkPath = $root.'/link.conf';
        @mkdir($root, 0755, true);
        file_put_contents($realPath, 'original');
        symlink($realPath, $linkPath);

        try {
            $this->assertTrue(!\pmssAtomicWriteFile($linkPath, 'updated'));
            $this->assertEquals('original', file_get_contents($realPath));
        } finally {
            @unlink($linkPath);
            @unlink($realPath);
            @rmdir($root);
        }
    }

    public function testWriteUserFileWritesContentAndMode(): void
    {
        $root = sys_get_temp_dir().'/pmss-lighttpd-write-'.uniqid('', true);
        $path = $root.'/custom.conf';
        $owner = '';
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $ownerInfo = @posix_getpwuid(posix_geteuid());
            $owner = is_array($ownerInfo) ? (string) ($ownerInfo['name'] ?? '') : '';
        }
        @mkdir($root, 0755, true);

        try {
            $this->assertTrue(\pmssWriteUserFile($path, 'server.modules = ()', $owner, 0640));
            $this->assertEquals('server.modules = ()', file_get_contents($path));
            $this->assertEquals(0640, fileperms($path) & 0777);
        } finally {
            @unlink($path);
            @rmdir($root);
        }
    }

    public function testUserConfigEntryPointKeepsLighttpdApplyHelperWiring(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/util/userConfigLighttpd.php');

        $this->assertStringContainsString("require_once dirname(__DIR__).'/lib/lighttpd/userConfigApply.php';", $src);
        $this->assertTrue(strpos($src, "require_once dirname(__DIR__).'/lib/lighttpd/delugeWebConf.php';") === false);
        $this->assertTrue(strpos($src, "require_once dirname(__DIR__).'/lib/lighttpd/proxyFragments.php';") === false);
        $this->assertTrue(strpos($src, "require_once dirname(__DIR__).'/lib/lighttpd/resourcePlan.php';") === false);
        $this->assertTrue(strpos($src, "require_once dirname(__DIR__).'/lib/lighttpd/userDirectoriesPrepare.php';") === false);
        $this->assertTrue(strpos($src, "require_once dirname(__DIR__).'/lib/lighttpd/configRender.php';") === false);
    }

    public function testUserConfigApplyOwnsPhpIniMemoryLimitUpdate(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/lib/lighttpd/userConfigApply.php');

        $this->assertStringContainsString("preg_match('/^memory_limit\\s*=.*$/m', \$phpIniContent)", $src);
        $this->assertStringContainsString('pmssAtomicWriteFile($phpIniPath, $phpIniContent);', $src);
    }

    public function testUserConfigApplyOwnsMovedHelperFunctions(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/lib/lighttpd/userConfigApply.php');

        foreach ([
            'pmssClampLighttpdBandwidthLimits',
            'pmssStripLighttpdWebdavConfig',
            'pmssParseSizeToMiB',
            'pmssComputePhpProcessPlan',
            'pmssShouldConfigureLighttpdForHome',
            'pmssEnsureWebdavLockDatabase',
            'pmssDelugeSessionsListDetected',
            'pmssDelugeNormalizeEmptySessionsObject',
            'pmssDelugeReadWebConf',
            'pmssDelugeWriteWebConf',
            'pmssDelugeLighttpdProxyFragment',
            'pmssRcloneLighttpdProxyFragment',
            'pmssQbittorrentLighttpdProxyFragment',
        ] as $functionName) {
            $this->assertStringContainsString('function '.$functionName.'(', $src);
        }
    }
}
