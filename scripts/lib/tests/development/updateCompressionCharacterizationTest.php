<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateCompressionCharacterizationTest extends TestCase
{
    public function testUpdateStep2KeepsInlineLighttpdHardeningStep(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssAdjust'.'LighttpdSecurity';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString("pmssRunProfiledStep('Adjusting lighttpd security settings'", $src);
        $this->assertStringContainsString("runStep('Restricting /etc/lighttpd directory permissions', 'chmod 750 /etc/lighttpd');", $src);
        $this->assertStringContainsString("logmsg('[SKIP] lighttpd .htpasswd missing; per-user instances manage authentication');", $src);
        $this->assertTrue(
            strpos($src, $symbol) === false,
            'update-step2.php should own the lighttpd hardening block directly'
        );
    }

    public function testWebStackDropsStandaloneLighttpdHardeningHelper(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/webStack.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssAdjust'.'LighttpdSecurity';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);
        $this->assertTrue(
            strpos($src, 'function '.$symbol) === false,
            'webStack.php should no longer export a one-use lighttpd hardening helper'
        );
    }

    public function testKillProcessKeepsGracefulAndForcedWaitPhasesLocally(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/runtime/processes.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssWaitFor'.'ProcessExit';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol) === false,
            'process wait logic should be localized inside killProcess()'
        );
        $this->assertStringContainsString("runStep(\$description.' (SIGTERM)'", $src);
        $this->assertStringContainsString("runStep(\$description.' (SIGKILL)'", $src);
        $this->assertStringContainsString('graceful stop', $src);
        $this->assertStringContainsString('processes linger after SIGKILL', $src);
    }

    public function testKillProcessKeepsProcessProbeLocal(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/runtime/processes.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssProcess'.'Running';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'process presence checks should stay localized inside killProcess()'
        );
        $this->assertStringContainsString("exec('pgrep -x '.escapeshellarg(\$name).' >/dev/null 2>&1'", $src);
        $this->assertStringContainsString('[SKIP] {$description} (no {$name} processes)', $src);
    }

    public function testUpdateStep2KeepsMediaareaBootstrapCleanupInline(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssCleanup'.'MediaareaBootstrapPackage';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString("pmssRunProfiledStep('Cleaning mediaarea bootstrap package state'", $src);
        $this->assertStringContainsString("dpkg-query -W -f=\${Status} repo-mediaarea 2>/dev/null", $src);
        $this->assertStringContainsString("runStep('Marking repo-mediaarea for deinstallation', \$setSelection);", $src);
        $this->assertTrue(
            strpos($src, $symbol) === false,
            'update-step2.php should own the mediaarea bootstrap cleanup directly'
        );
    }

    public function testRootlessDockerUnitParsingStaysInsideUserMaintenance(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/userMaintenance.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssReadSystemd'.'UnitExecStartBinary';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol) === false,
            'userMaintenance.php should keep the docker ExecStart parse local to the stale-unit check'
        );
        $this->assertStringContainsString("if (strpos(\$trim, 'ExecStart=') !== 0)", $src);
        $this->assertStringContainsString("\$execBinary = trim(\$parts[0], \"\\\"'\");", $src);
    }

    public function testQuotaSnapshotKeepsSizeTokenNormalizationLocal(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/quotaSnapshot.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssQuotaSnapshotNormalize'.'SizeToken';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'quotaSnapshot.php should keep bare-size token normalization inside the line normalizer'
        );
        $this->assertStringContainsString("preg_match('/^([0-9]+)(\\*)?$/', \$tokens[\$index], \$matches)", $src);
        $this->assertStringContainsString("\$normalizedToken = \$matches[1].'K'.(\$matches[2] ?? '');", $src);
    }

    public function testQbittorrentPortEnsureKeepsAtomicRewriteInline(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/user/torrentPort.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssTorrentPort'.'FileWrite';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'torrentPort.php should keep the qBittorrent atomic rewrite local to pmssQbittorrentPortEnsure()'
        );
        $this->assertStringContainsString("@tempnam(\$dir, basename(\$configPath).'.pmss-tmp-')", $src);
        $this->assertStringContainsString("@rename(\$tmp, \$configPath)", $src);
    }

    public function testUserConfigKeepsQbittorrentBootstrapInline(): void
    {
        $userConfigPath = dirname(__DIR__, 4).'/scripts/util/userConfig.php';
        $userConfigSrc = @file_get_contents($userConfigPath);
        $libraryPath = dirname(__DIR__, 4).'/scripts/lib/user/qbittorrent.php';
        $librarySrc = @file_get_contents($libraryPath);
        $symbol = 'userConfigure'.'Qbittorrent';

        $this->assertTrue(is_string($userConfigSrc) && $userConfigSrc !== '', 'Expected to read '.$userConfigPath);
        $this->assertTrue(is_string($librarySrc) && $librarySrc !== '', 'Expected to read '.$libraryPath);
        $this->assertTrue(
            strpos($librarySrc, 'function '.$symbol.'(') === false,
            'qbittorrent.php should no longer export a one-call user bootstrap wrapper'
        );
        $this->assertStringContainsString('template.qbittorrent.conf', $userConfigSrc);
        $this->assertStringContainsString("pmssQbittorrentApplyUploadThrottle(\$user['name'], \$throttle);", $userConfigSrc);
    }

    public function testSuspendLandingKeepsTemplateLoadInline(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/suspend.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssRender'.'SuspendedHtml';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'suspend.php should keep suspended landing template selection inside pmssCreateSuspendedLanding()'
        );
        $this->assertStringContainsString("/etc/seedbox/config/template.suspended.notice.html", $src);
        $this->assertStringContainsString("pmssSuspendedFallbackHtml(\$username)", $src);
        $this->assertStringContainsString("@file_put_contents(\$suspendRoot.'/index.html', \$html)", $src);
    }

    public function testStorageHealthSnapshotKeepsLsblkParsingLocal(): void
    {
        $snapshotPath = dirname(__DIR__, 4).'/scripts/util/storageHealthSnapshot.php';
        $snapshotSrc = @file_get_contents($snapshotPath);
        $libraryPath = dirname(__DIR__, 4).'/scripts/lib/storageHealth/disks.php';
        $facadePath = dirname(__DIR__, 4).'/scripts/lib/storageHealth.php';
        $facadeSrc = @file_get_contents($facadePath);
        $symbol = 'pmssStorageHealthList'.'DisksFromLsblk';

        $this->assertTrue(is_string($snapshotSrc) && $snapshotSrc !== '', 'Expected to read '.$snapshotPath);
        $this->assertTrue(is_string($facadeSrc) && $facadeSrc !== '', 'Expected to read '.$facadePath);
        $this->assertTrue(!is_file($libraryPath), 'Expected one-call storageHealth/disks.php helper file to be removed');
        $this->assertTrue(
            strpos($snapshotSrc, $symbol.'(') === false,
            'storageHealthSnapshot.php should keep lsblk parsing local to pmssStorageHealthSnapshotMain()'
        );
        $this->assertStringContainsString("preg_split('/\\r?\\n/', trim((string) \$lsblkOut))", $snapshotSrc);
        $this->assertStringContainsString("strpos(\$kname, 'loop') === 0", $snapshotSrc);
        $this->assertTrue(
            strpos($facadeSrc, "'disks'") === false,
            'storageHealth.php should stop requiring the removed disks.php module'
        );
    }

    public function testResourceSnapshotCronOwnsSnapshotLoop(): void
    {
        $cronPath = dirname(__DIR__, 4).'/scripts/cron/resourceSnapshot.php';
        $cronSrc = @file_get_contents($cronPath);
        $libraryPath = dirname(__DIR__, 4).'/scripts/lib/resources/snapshot.php';
        $symbol = 'pmssResource'.'SnapshotRun';
        $this->assertTrue(is_string($cronSrc) && $cronSrc !== '', 'Expected to read '.$cronPath);
        $this->assertTrue(!is_file($libraryPath), 'Expected one-call resources snapshot library file to be removed');
        $this->assertStringContainsString("require_once __DIR__.'/../lib/resources/log.php';", $cronSrc);
        $this->assertStringContainsString('const PMSS_RESOURCE_SNAPSHOT_LOG_DEFAULT', $cronSrc);
        $this->assertStringContainsString('new ResourceStatsAccumulator([\'day\' => $threshold])', $cronSrc);
        $this->assertStringContainsString('exit(pmssResourceSnapshotRun());', $cronSrc);
        $this->assertStringContainsString('function '.$symbol.'(): int', $cronSrc);
    }
}
