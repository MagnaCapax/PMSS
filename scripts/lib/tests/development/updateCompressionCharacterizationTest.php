<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateCompressionCharacterizationTest extends TestCase
{
    public function testStartRtorrentReusesSharedProcessLookups(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/startRtorrent';
        $src = @file_get_contents($path);

        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);
        $this->assertStringContainsString("rtorrentProcessPgrepExact(\$user, 'rtorrent', \$rtorrentRc, \$rtorrentOut)", $src);
        $this->assertStringContainsString("rtorrentProcessExecutorPids(\$user, \$executorRc, \$executorOut)['all']", $src);
    }

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

    public function testAptSourcesDebianSelectionUsesSharedReleaseSpecs(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/apt.php';
        $src = @file_get_contents($path);
        $legacyTable = 'static $targets = [';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString("require_once __DIR__.'/distro.php';", $src);
        $this->assertStringContainsString('pmssDebianReleaseSpecs()[$version] ?? null', $src);
        $this->assertTrue(
            strpos($src, $legacyTable) === false,
            'apt.php should not keep a second Debian release target table'
        );
    }

    public function testUpdateStep2OwnsWebStackConfiguration(): void
    {
        $step2Path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $step2Src = @file_get_contents($step2Path);
        $modulePath = dirname(__DIR__, 4).'/scripts/lib/update/webStack.php';
        $this->assertTrue(is_string($step2Src) && $step2Src !== '', 'Expected to read '.$step2Path);
        $this->assertTrue(!is_file($modulePath), 'Expected one-call update/webStack.php helper file to be removed');
        $this->assertTrue(
            strpos($step2Src, "require_once __DIR__.'/../lib/update/webStack.php';") === false,
            'update-step2.php should stop requiring the removed webStack.php module'
        );
        $this->assertStringContainsString('function pmssConfigureWebStack(int $distroVersion): void', $step2Src);
        $this->assertStringContainsString("runStep('Stopping nginx prior to configuration refresh'", $step2Src);
        $this->assertStringContainsString("pmssSystemdUnitActionIfPresent('lighttpd', 'Disabling lighttpd systemd service', 'disable');", $step2Src);
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
        $this->assertStringContainsString("foreach (['TERM' => max(0, \$timeoutSeconds), 'KILL' => 5] as \$signal => \$waitSeconds)", $src);
        $this->assertStringContainsString("runStep(\$description.' (SIG'.\$signal.')'", $src);
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
        $this->assertStringContainsString("\$probeCommand = 'pgrep -x '.escapeshellarg(\$name).' >/dev/null 2>&1';", $src);
        $this->assertStringContainsString('exec($probeCommand, $_, $probeStatus);', $src);
        $this->assertStringContainsString('[SKIP] {$description} (no {$name} processes)', $src);
    }

    public function testKillProcessKeepsLocalWaitLoopsInlineWithoutClosures(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/runtime/processes.php';
        $src = @file_get_contents($path);
        $probeNeedle = '$process'.'Running = static function';
        $waitNeedle = '$waitFor'.'ProcessExit = static function';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, $probeNeedle) === false,
            'killProcess() should keep the process probe inline without a local closure'
        );
        $this->assertTrue(
            strpos($src, $waitNeedle) === false,
            'killProcess() should keep the wait loops inline without a local closure'
        );
        $this->assertStringContainsString('$deadline = microtime(true) + $waitSeconds;', $src);
        $this->assertStringContainsString('usleep(250000);', $src);
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

    public function testUpdateLoggingKeepsCorrelationIdBuildLocal(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/logging.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssBuild'.'CorrelationId';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'logging.php should keep correlation ID generation inside pmssCorrelationId()'
        );
        $this->assertStringContainsString("gmdate('Ymd-His')", $src);
        $this->assertStringContainsString("bin2hex(random_bytes(3))", $src);
        $this->assertStringContainsString("substr(hash('sha256', \$timestamp.\$host.microtime(true)), 0, 6)", $src);
    }

    public function testBootstrapUpdateKeepsCorrelationIdBuildLocal(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/update.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssBuild'.'CorrelationId';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'update.php should keep correlation ID generation inside pmssCorrelationId()'
        );
        $this->assertStringContainsString("bin2hex(random_bytes(3))", $src);
        $this->assertStringContainsString("PMSS_CORRELATION_ENV.'='.\$generated", $src);
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
        $this->assertStringContainsString("\$tokens[\$index] = \$matches[1].'K'.(\$matches[2] ?? '');", $src);
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

    public function testShowTrafficKeepsLocalnetSplitAndBarRenderingInline(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/showTraffic.php';
        $src = @file_get_contents($path);
        $splitSymbol = 'pmssShowTrafficSplit'.'LocalnetUser';
        $barSymbol = 'pmssShowTrafficRender'.'Bar';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$splitSymbol.'(') === false,
            'showTraffic.php should keep localnet suffix detection inside pmssShowTrafficMain()'
        );
        $this->assertTrue(
            strpos($src, 'function '.$barSymbol.'(') === false,
            'showTraffic.php should keep the extended output bar rendering inside pmssShowTrafficMain()'
        );
        $this->assertStringContainsString("substr(\$thisUser, -strlen('-localnet')) === '-localnet'", $src);
        $this->assertStringContainsString("str_repeat('#', \$filled)", $src);
        $this->assertStringContainsString("str_repeat('-', 10 - \$filled)", $src);
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

    public function testStorageHealthSnapshotKeepsJsonAppendsInline(): void
    {
        $snapshotPath = dirname(__DIR__, 4).'/scripts/util/storageHealthSnapshot.php';
        $snapshotSrc = @file_get_contents($snapshotPath);
        $wrapperNeedle = '$append'.'Json = static function';

        $this->assertTrue(is_string($snapshotSrc) && $snapshotSrc !== '', 'Expected to read '.$snapshotPath);
        $this->assertTrue(
            strpos($snapshotSrc, $wrapperNeedle) === false,
            'storageHealthSnapshot.php should keep JSONL appends inline in pmssStorageHealthSnapshotMain()'
        );
        $this->assertStringContainsString(
            'json_encode(pmssStorageHealthSnapshotSmart($disk, $last, $timestamp), JSON_UNESCAPED_SLASHES).PHP_EOL',
            $snapshotSrc
        );
        $this->assertStringContainsString('json_encode($nvme, JSON_UNESCAPED_SLASHES).PHP_EOL', $snapshotSrc);
        $this->assertStringContainsString('json_encode($raid, JSON_UNESCAPED_SLASHES).PHP_EOL', $snapshotSrc);
    }

    public function testStorageHealthSnapshotKeepsJsonOptionConsumptionInline(): void
    {
        $snapshotPath = dirname(__DIR__, 4).'/scripts/util/storageHealthSnapshot.php';
        $snapshotSrc = @file_get_contents($snapshotPath);
        $helperSymbol = 'pmssStorageHealthSnapshot'.'ParseCli';

        $this->assertTrue(is_string($snapshotSrc) && $snapshotSrc !== '', 'Expected to read '.$snapshotPath);
        $this->assertTrue(
            strpos($snapshotSrc, 'function '.$helperSymbol.'(') === false,
            'storageHealthSnapshot.php should keep CLI option consumption inside pmssStorageHealthSnapshotMain()'
        );
        $this->assertStringContainsString("strpos(\$argv[\$i + 1], '--') !== 0", $snapshotSrc);
        $this->assertStringContainsString("\$val = \$argv[++\$i];", $snapshotSrc);
        $this->assertStringContainsString("if (\$key !== '--json') {", $snapshotSrc);
    }

    public function testStorageBenchmarkDropsStandaloneCliConsumeHelper(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/storageBenchmark.php';
        $src = @file_get_contents($path);
        $helperSymbol = 'consume'.'CliValue';

        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);
        $this->assertTrue(
            strpos($src, 'function '.$helperSymbol.'(') === false,
            'storageBenchmark.php should inline CLI option consumption instead of keeping a standalone helper'
        );
        $this->assertStringContainsString("'--device-runtime'", $src);
        $this->assertStringContainsString("'--require-idle'", $src);
    }

    public function testStorageHealthFacadeDropsStandaloneExecModule(): void
    {
        $facadePath = dirname(__DIR__, 4).'/scripts/lib/storageHealth.php';
        $facadeSrc = @file_get_contents($facadePath);
        $commonPath = dirname(__DIR__, 4).'/scripts/lib/storageHealth/common.php';
        $commonSrc = @file_get_contents($commonPath);
        $libraryPath = dirname(__DIR__, 4).'/scripts/lib/storageHealth/exec.php';
        $symbol = 'pmssStorageHealthExec'.'Capture';

        $this->assertTrue(is_string($facadeSrc) && $facadeSrc !== '', 'Expected to read '.$facadePath);
        $this->assertTrue(is_string($commonSrc) && $commonSrc !== '', 'Expected to read '.$commonPath);
        $this->assertTrue(!is_file($libraryPath), 'Expected one-call storageHealth/exec.php helper file to be removed');
        $this->assertTrue(
            strpos($facadeSrc, "'exec'") === false,
            'storageHealth.php should stop requiring the removed exec.php module'
        );
        $this->assertStringContainsString('function '.$symbol.'(', $commonSrc);
        $this->assertStringContainsString("return ['rc' => 124, 'stdout' => \$stdout, 'stderr' => \$stderr];", $commonSrc);
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

    public function testUpdateLibraryDropsStandaloneSkeletonPathHelper(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssSkeleton'.'Path';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'update.php should keep skeleton path joins inline inside updateUserFile()'
        );
        $this->assertStringContainsString("pmssSkeletonBase().'/'.\$file", $src);
    }

    public function testSkeletonMaintenanceKeepsTorrentFrontendPatchLocal(): void
    {
        $usersPath = dirname(__DIR__, 4).'/scripts/lib/update/users.php';
        $filesystemPath = dirname(__DIR__, 4).'/scripts/lib/update/users/filesystem.php';
        $src = @file_get_contents($usersPath);
        $filesystemSrc = @file_get_contents($filesystemPath);
        $symbol = 'pmssUserPatch'.'TorrentFrontends';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$usersPath);
        $this->assertTrue(is_string($filesystemSrc) && $filesystemSrc !== '', 'Expected to read '.$filesystemPath);

        $this->assertTrue(
            strpos($filesystemSrc, 'function '.$symbol.'(') === false,
            'filesystem.php should keep torrent frontend patch logic local to pmssUserApplySkeletonFiles()'
        );
        $this->assertStringContainsString("require_once __DIR__.'/users/filesystem.php';", $src);
        $this->assertStringContainsString("preg_replace('/^<\\?php\\s*/', \$requireLine, \$updated, 1, \$count)", $filesystemSrc);
        $this->assertStringContainsString('pmssDelugePortEnsureCurrentUser', $filesystemSrc);
        $this->assertStringContainsString('pmssQbittorrentPortEnsureCurrentUser', $filesystemSrc);
    }

    public function testUserUpdateModuleOwnsRutorrentHelpers(): void
    {
        $usersPath = dirname(__DIR__, 4).'/scripts/lib/update/users.php';
        $usersSrc = @file_get_contents($usersPath);
        $rutorrentPath = dirname(__DIR__, 4).'/scripts/lib/update/users/rutorrent.php';
        $rutorrentSrc = @file_get_contents($rutorrentPath);
        $symbol = 'pmssUserUpgrade'.'Rutorrent';
        $this->assertTrue(is_string($usersSrc) && $usersSrc !== '', 'Expected to read '.$usersPath);
        $this->assertTrue(is_string($rutorrentSrc) && $rutorrentSrc !== '', 'Expected to read '.$rutorrentPath);

        $this->assertTrue(
            strpos($usersSrc, 'function '.$symbol.'(') === false,
            'users.php should stay thin and delegate ruTorrent maintenance'
        );
        $this->assertStringContainsString("require_once __DIR__.'/users/rutorrent.php';", $usersSrc);
        $this->assertStringContainsString('function pmssUserUpgradeRutorrent(', $rutorrentSrc);
        $this->assertStringContainsString('function pmssUserMaintainRutorrentPhpCompatibility(', $rutorrentSrc);
        $this->assertStringContainsString('function pmssUserUpdateThemes(', $rutorrentSrc);
    }

    public function testOsReleaseHelpersKeepPathLookupInline(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/osRelease.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssOsRelease'.'Path';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'osRelease.php should keep the os-release path lookup inline inside cache helpers'
        );
        $this->assertStringContainsString("pmssResolvePathFromEnv('PMSS_OS_RELEASE_PATH', '/etc/os-release')", $src);
    }

    public function testRuntimeProfileKeepsStoreInitializationInsideRecordProfile(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/runtime/profile.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssInit'.'ProfileStore';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'runtime/profile.php should keep profile-store initialization inside pmssRecordProfile()'
        );
        $this->assertStringContainsString("if (!is_array(\$GLOBALS['PMSS_PROFILE'] ?? null))", $src);
        $this->assertStringContainsString("\$GLOBALS['PMSS_PROFILE'][] = \$entry;", $src);
    }

    public function testUserMaintenanceKeepsOptionalPostChecksInline(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/userMaintenance.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssRunUser'.'PostCheck';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'userMaintenance.php should keep optional htpasswd/lighttpd checks inside pmssUpdateAllUsers()'
        );
        $this->assertStringContainsString('Synchronizing per-user htpasswd', $src);
        $this->assertStringContainsString('Checking lighttpd instance', $src);
    }

    public function testUserMaintenanceKeepsProfilePayloadLocal(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/userMaintenance.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssBuild'.'UserMaintenanceProfile';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'userMaintenance.php should keep per-user profile payload assembly inside pmssUpdateAllUsers()'
        );
        $this->assertStringContainsString("'description'    => 'updateUser '.\$user", $src);
        $this->assertStringContainsString("'stdout_excerpt' => ''", $src);
        $this->assertStringContainsString("'stderr_excerpt' => \$stderrExcerpt", $src);
    }

    public function testDockerDependenciesKeepDaemonJsonWritesLocal(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/userMaintenance.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssWrite'.'DockerDaemonConfig';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'userMaintenance.php should keep daemon.json write handling inside pmssEnsureDockerDependencies()'
        );
        $this->assertStringContainsString('JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES', $src);
        $this->assertStringContainsString("'native.cgroupdriver=cgroupfs'", $src);
    }

    public function testRepositoryPrerequisitesKeepSonarrDetectionInline(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/repositories.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssSonarr'.'SourceLine';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'repositories.php should keep Sonarr source detection inside signed-by rewriting'
        );
        $this->assertStringContainsString("preg_match('/^[ \\t]*#/', \$line) === 1", $src);
        $this->assertStringContainsString('signed-by=', $src);
    }

    public function testPluginMaintenanceOwnsRetrackerCleanup(): void
    {
        $pluginsPath = dirname(__DIR__, 4).'/scripts/lib/update/users/rutorrent.php';
        $usersPath = dirname(__DIR__, 4).'/scripts/lib/update/users.php';
        $usersSrc = @file_get_contents($usersPath);
        $pluginsSrc = @file_get_contents($pluginsPath);
        $symbol = 'pmssUserMaintain'.'Retracker';
        $this->assertTrue(is_string($usersSrc) && $usersSrc !== '', 'Expected to read '.$usersPath);
        $this->assertTrue(is_string($pluginsSrc) && $pluginsSrc !== '', 'Expected to read '.$pluginsPath);

        $this->assertTrue(
            strpos($pluginsSrc, 'function '.$symbol.'(') === false,
            'rutorrent.php should keep retracker cleanup inside pmssUserEnsurePlugins()'
        );
        $this->assertStringContainsString("require_once __DIR__.'/users/rutorrent.php';", $usersSrc);
        $this->assertStringContainsString('retrackers.dat', $pluginsSrc);
        $this->assertStringContainsString('Creating ruTorrent RSS settings directory', $pluginsSrc);
    }

    public function testUserUpdateModuleOwnsContextAndHttpHelpers(): void
    {
        $usersPath = dirname(__DIR__, 4).'/scripts/lib/update/users.php';
        $usersSrc = @file_get_contents($usersPath);
        $contextPath = dirname(__DIR__, 4).'/scripts/lib/update/users/context.php';
        $httpPath = dirname(__DIR__, 4).'/scripts/lib/update/users/http.php';
        $contextSrc = @file_get_contents($contextPath);
        $httpSrc = @file_get_contents($httpPath);
        $this->assertTrue(is_string($usersSrc) && $usersSrc !== '', 'Expected to read '.$usersPath);
        $this->assertTrue(is_string($contextSrc) && $contextSrc !== '', 'Expected to read '.$contextPath);
        $this->assertTrue(is_string($httpSrc) && $httpSrc !== '', 'Expected to read '.$httpPath);

        $this->assertTrue(
            strpos($usersSrc, 'function pmssBuildUserContext(') === false,
            'users.php should delegate context building to a domain module'
        );
        $this->assertTrue(
            strpos($usersSrc, 'function pmssUserConfigureHttp(') === false,
            'users.php should delegate HTTP maintenance to a domain module'
        );
        $this->assertStringContainsString("require_once __DIR__.'/users/context.php';", $usersSrc);
        $this->assertStringContainsString("require_once __DIR__.'/users/http.php';", $usersSrc);
        $this->assertStringContainsString('function pmssBuildUserContext(', $contextSrc);
        $this->assertStringContainsString('www-disabled', $contextSrc);
        $this->assertStringContainsString('function pmssUserConfigureHttp(', $httpSrc);
        $this->assertStringContainsString('HostHeaderValidation', $httpSrc);
    }

    public function testUserUpdateModuleOwnsPermissionRefreshHelper(): void
    {
        $usersPath = dirname(__DIR__, 4).'/scripts/lib/update/users.php';
        $usersSrc = @file_get_contents($usersPath);
        $permissionsPath = dirname(__DIR__, 4).'/scripts/lib/update/users/filesystem.php';
        $permissionsSrc = @file_get_contents($permissionsPath);
        $this->assertTrue(is_string($usersSrc) && $usersSrc !== '', 'Expected to read '.$usersPath);
        $this->assertTrue(is_string($permissionsSrc) && $permissionsSrc !== '', 'Expected to read '.$permissionsPath);

        $this->assertTrue(
            strpos($usersSrc, 'function pmssUserRefreshPermissions(') === false,
            'users.php should delegate permission refresh to filesystem.php'
        );
        $this->assertStringContainsString("require_once __DIR__.'/users/filesystem.php';", $usersSrc);
        $this->assertStringContainsString('function pmssUserRefreshPermissions(', $permissionsSrc);
        $this->assertStringContainsString('PMSS_USER_PERMISSIONS_TIMEOUT', $permissionsSrc);
        $this->assertStringContainsString("'-c3'", $permissionsSrc);
    }

    public function testUserDomainModulesDoNotCrossRequireEachOther(): void
    {
        $base = dirname(__DIR__, 4).'/scripts/lib/update/users';
        foreach (['context.php', 'http.php', 'filesystem.php', 'rutorrent.php'] as $file) {
            $path = $base.'/'.$file;
            $src = @file_get_contents($path);
            $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);
            $this->assertTrue(
                strpos($src, "require_once __DIR__.'/") === false,
                $file.' should not require sibling domain modules'
            );
        }
    }

    public function testUserUpdateEntrypointKeepsDirectHandlerSequence(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/users.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString("'pmssUserConfigureHttp'", $src);
        $this->assertStringContainsString("'pmssUserApplySkeletonFiles'", $src);
        $this->assertStringContainsString("'pmssUserUpgradeRutorrent'", $src);
        $this->assertStringContainsString("'pmssUserRefreshPermissions'", $src);
        $this->assertTrue(
            strpos($src, 'Missing handler') === false,
            'users.php should not keep a dead missing-handler warning branch once domain modules are required directly'
        );
    }

    public function testUserMaintenanceKeepsDirectPhaseSummaryAndSummaryLogging(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/userMaintenance.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString('Environment (HTTP/ruTorrent/permissions + linger/systemd/rootless Docker)', $src);
        $this->assertStringContainsString("pmssUserLog(\$userTrim, '[WARN] update-step2 user maintenance aborted: '.\$reason);", $src);
        $this->assertStringContainsString('pmssLogJson([', $src);
        $this->assertTrue(
            strpos($src, "function_exists('pmssUpdateUserEnvironment')") === false,
            'userMaintenance.php should not guard helpers that are required at file load time'
        );
        $this->assertTrue(
            strpos($src, "function_exists('pmssLogJson')") === false,
            'userMaintenance.php should log its JSON summary directly through the required logging runtime'
        );
        $this->assertTrue(
            strpos($src, "function_exists('pmssUserDockerEnabled')") === false,
            'userMaintenance.php should call the required Docker config helper directly'
        );
        foreach ([
            "if (!function_exists('pmssRunAndLog'))",
            "if (!function_exists('pmssUpdateAllUsers'))",
            "if (!function_exists('pmssEnsureLingerAndDocker'))",
            "if (!function_exists('pmssEnsureRootlessDockerInstalled'))",
            "if (!function_exists('pmssEnsureDockerDependencies'))",
        ] as $deadGuard) {
            $this->assertTrue(
                strpos($src, $deadGuard) === false,
                'userMaintenance.php should not keep dead self-guard wrappers once runtime callers use require_once'
            );
        }
    }

    public function testDistUpgradeUsesRequiredRepairHelpersDirectly(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/distUpgrade.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString('pmssEnsureBootDefaults(', $src);
        $this->assertStringContainsString('pmssEnsureRootlessDockerInstalled($user);', $src);
        $this->assertStringContainsString('pmssEnsureDockerDependencies($user);', $src);
        $this->assertStringContainsString("pmssUserLog(\$userTrim, '[SKIP] dist-upgrade: user appears suspended; skipping rootless Docker repair');", $src);
        $this->assertStringContainsString("pmssUserLog(\$user, 'dist-upgrade: rootless Docker repair start');", $src);
        $this->assertTrue(
            strpos($src, "function_exists('pmssEnsureBootDefaults')") === false,
            'distUpgrade.php should call the required boot defaults helper directly'
        );
        $this->assertTrue(
            strpos($src, "class_exists('users')") === false,
            'distUpgrade.php should not keep a dead users class guard once userMaintenance.php is required'
        );
        $this->assertTrue(
            strpos($src, "function_exists('pmssEnsureRootlessDockerInstalled')") === false,
            'distUpgrade.php should not keep dead rootless helper guards once userMaintenance.php is required'
        );
        $this->assertTrue(
            strpos($src, "function_exists('pmssUserLog')") === false,
            'distUpgrade.php should log through the required user logger directly'
        );
    }
}
