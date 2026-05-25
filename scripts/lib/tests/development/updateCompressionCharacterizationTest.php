<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateCompressionCharacterizationTest extends TestCase
{
    private function assertSourceOmitsFunction(string $source, string $symbol, string $message): void
    {
        $this->assertTrue(strpos($source, 'function '.$symbol.'(') === false, $message);
    }

    public function testStartRtorrentReusesSharedProcessLookups(): void
    {
        $src = $this->pmssReadRepoFile('scripts/startRtorrent');
        $this->assertStringContainsString("rtorrentProcessPgrepExact(\$user, 'rtorrent', \$rtorrentRc, \$rtorrentOut)", $src);
        $this->assertStringContainsString("rtorrentProcessExecutorPids(\$user, \$executorRc, \$executorOut)['all']", $src);
    }

    public function testUpdateStep2KeepsInlineLighttpdHardeningStep(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');
        $symbol = 'pmssAdjust'.'LighttpdSecurity';

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
        $src = $this->pmssReadRepoFile('scripts/lib/update/apt.php');
        $legacyTable = 'static $targets = [';

        $this->assertStringContainsString("require_once __DIR__.'/distro.php';", $src);
        $this->assertStringContainsString('pmssDebianReleaseSpecs()[$version] ?? null', $src);
        $this->assertTrue(
            strpos($src, $legacyTable) === false,
            'apt.php should not keep a second Debian release target table'
        );
    }

    public function testNginxSubdomainTemplateOutputSnapshot(): void
    {
        require_once dirname(__DIR__, 3).'/lib/nginxConfig/templates.php';
        $this->assertSame('86ea6ac663b4a48a25b6a8ad4718787cb671dc376bb121bcdd3aa21c51480c8d', hash('sha256', implode("\n---PMSS-TEMPLATE---\n", \pmssNginxUserSubdomainTemplates())), 'nginx subdomain template output changed');
    }

    public function testUpdateStep2OwnsWebStackConfiguration(): void
    {
        $step2Src = $this->pmssReadRepoFile('scripts/util/update-step2.php');
        $modulePath = dirname(__DIR__, 4).'/scripts/lib/update/webStack.php';
        $this->assertTrue(!is_file($modulePath), 'Expected one-call update/webStack.php helper file to be removed');
        $this->pmssAssertStringNotContainsString(
            "require_once __DIR__.'/../lib/update/webStack.php';",
            $step2Src,
            'update-step2.php should stop requiring the removed webStack.php module'
        );
        $this->assertStringContainsString('function pmssConfigureWebStack(): void', $step2Src);
        $this->assertStringContainsString("runStep('Stopping nginx prior to configuration refresh'", $step2Src);
        $this->assertStringContainsString("pmssSystemdUnitActionIfPresent('lighttpd', 'Disabling lighttpd systemd service', 'disable');", $step2Src);
        $this->assertStringContainsString("if (\$reportedVersion > 0 && \$reportedVersion < 10)", $step2Src);
        $this->pmssAssertStringNotContainsString(
            'update-rc.d lighttpd',
            $step2Src,
            'update-step2.php aborts unsupported Debian versions before web-stack configuration, so legacy sysvinit branches should stay removed'
        );
    }

    public function testKillProcessKeepsGracefulAndForcedWaitPhasesLocally(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/runtime/processes.php');
        $symbol = 'pmssWaitFor'.'ProcessExit';

        $this->assertSourceOmitsFunction($src, $symbol, 'process wait logic should be localized inside killProcess()');
        $this->assertStringContainsString("foreach (['TERM' => max(0, \$timeoutSeconds), 'KILL' => 5] as \$signal => \$waitSeconds)", $src);
        $this->assertStringContainsString("runStep(\$description.' (SIG'.\$signal.')'", $src);
        $this->assertStringContainsString('graceful stop', $src);
        $this->assertStringContainsString('processes linger after SIGKILL', $src);
    }

    public function testKillProcessKeepsProcessProbeLocal(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/runtime/processes.php');
        $symbol = 'pmssProcess'.'Running';

        $this->assertSourceOmitsFunction($src, $symbol, 'process presence checks should stay localized inside killProcess()');
        $this->assertStringContainsString("\$probeCommand = 'pgrep -x '.escapeshellarg(\$name).' >/dev/null 2>&1';", $src);
        $this->assertStringContainsString('exec($probeCommand, $_, $probeStatus);', $src);
        $this->assertStringContainsString('[SKIP] {$description} (no {$name} processes)', $src);
    }

    public function testKillProcessKeepsLocalWaitLoopsInlineWithoutClosures(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/runtime/processes.php');
        $probeNeedle = '$process'.'Running = static function';
        $waitNeedle = '$waitFor'.'ProcessExit = static function';

        $this->pmssAssertStringNotContainsString(
            $probeNeedle,
            $src,
            'killProcess() should keep the process probe inline without a local closure'
        );
        $this->pmssAssertStringNotContainsString(
            $waitNeedle,
            $src,
            'killProcess() should keep the wait loops inline without a local closure'
        );
        $this->assertStringContainsString('$deadline = microtime(true) + $waitSeconds;', $src);
        $this->assertStringContainsString('usleep(250000);', $src);
    }

    public function testUpdateStep2KeepsMediaareaBootstrapCleanupInline(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');
        $symbol = 'pmssCleanup'.'MediaareaBootstrapPackage';

        $this->assertStringContainsString("pmssRunProfiledStep('Cleaning mediaarea bootstrap package state'", $src);
        $this->assertStringContainsString("dpkg-query -W -f=\${Status} repo-mediaarea 2>/dev/null", $src);
        $this->assertStringContainsString("runStep('Marking repo-mediaarea for deinstallation', \$setSelection);", $src);
        $this->pmssAssertStringNotContainsString(
            $symbol,
            $src,
            'update-step2.php should own the mediaarea bootstrap cleanup directly'
        );
    }

    public function testRootlessDockerUnitParsingStaysInsideUserMaintenance(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/userMaintenance.php');
        $symbol = 'pmssReadSystemd'.'UnitExecStartBinary';

        $this->assertSourceOmitsFunction($src, $symbol, 'userMaintenance.php should keep the docker ExecStart parse local to the stale-unit check');
        $this->assertStringContainsString("if (strpos(\$trim, 'ExecStart=') !== 0)", $src);
        $this->assertStringContainsString("\$execBinary = trim(\$parts[0], \"\\\"'\");", $src);
    }

    public function testSystemdDropinInstallerKeepsSingleFailurePrefix(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/systemPrep/systemdSlicesEnsure.php');
        $removedHelper = 'pmssSystemdDropin'.'Install';
        $deadPrefixSymbol = '$write'.'FailurePrefix';
        $deadTempPrefixMessage = 'Failed to write'.' temp';

        $this->assertSourceOmitsFunction($src, $removedHelper, 'systemd drop-in writes should use the shared managed-file writer directly');
        $this->assertStringContainsString("pmssWriteManagedPathFile(\$target, \$raw, 'systemd drop-in'", $src);
        $this->assertStringContainsString("pmssWriteManagedPathFile(\$userAtTarget, \$userAtBody, 'systemd drop-in'", $src);
        $this->pmssAssertStringNotContainsString($deadPrefixSymbol, $src, 'systemd drop-in writes should not keep a dead temp-write prefix concept');
        $this->pmssAssertStringNotContainsString($deadTempPrefixMessage, $src, 'systemd drop-in callers should log the single managed-write failure path');
    }

    public function testUpdateLoggingKeepsCorrelationIdBuildLocal(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/logging.php');
        $symbol = 'pmssBuild'.'CorrelationId';

        $this->assertSourceOmitsFunction($src, $symbol, 'logging.php should keep correlation ID generation inside pmssCorrelationId()');
        $this->assertStringContainsString("gmdate('Ymd-His')", $src);
        $this->assertStringContainsString("bin2hex(random_bytes(3))", $src);
        $this->assertStringContainsString("substr(hash('sha256', \$timestamp.\$host.microtime(true)), 0, 6)", $src);
    }

    public function testBootstrapUpdateKeepsCorrelationIdBuildLocal(): void
    {
        $src = $this->pmssReadRepoFile('scripts/update.php');
        $symbol = 'pmssBuild'.'CorrelationId';

        $this->assertSourceOmitsFunction($src, $symbol, 'update.php should keep correlation ID generation inside pmssCorrelationId()');
        $this->assertStringContainsString("bin2hex(random_bytes(3))", $src);
        $this->assertStringContainsString("PMSS_CORRELATION_ENV.'='.\$generated", $src);
    }

    public function testQuotaSnapshotKeepsSizeTokenNormalizationLocal(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/quotaSnapshot.php');
        $symbol = 'pmssQuotaSnapshotNormalize'.'SizeToken';

        $this->assertSourceOmitsFunction($src, $symbol, 'quotaSnapshot.php should keep bare-size token normalization inside the line normalizer');
        $this->assertStringContainsString("preg_match('/^([0-9]+)(\\*)?$/', \$tokens[\$index], \$matches)", $src);
        $this->assertStringContainsString("\$tokens[\$index] = \$matches[1].'K'.(\$matches[2] ?? '');", $src);
    }

    public function testQbittorrentPortEnsureUsesSharedConfigWriter(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/user/torrentPort.php');
        $symbol = 'pmssTorrentPort'.'FileWrite';

        $this->assertSourceOmitsFunction($src, $symbol, 'torrentPort.php should not grow a second qBittorrent file-writer helper');
        $this->assertStringContainsString("require_once __DIR__.'/qbittorrent.php';", $src);
        $this->assertStringContainsString('pmssQbittorrentConfigMutate(', $src);
        $this->pmssAssertStringNotContainsString(
            '@tempnam($dir, basename($configPath).\'.pmss-tmp-\')',
            $src,
            'qBittorrent port repair should use the shared config writer instead of an inline temp-file path'
        );
    }

    public function testUserConfigKeepsQbittorrentBootstrapInline(): void
    {
        $userConfigSrc = $this->pmssReadRepoFile('scripts/util/userConfig.php');
        $librarySrc = $this->pmssReadRepoFile('scripts/lib/user/qbittorrent.php');
        $symbol = 'userConfigure'.'Qbittorrent';

        $this->assertSourceOmitsFunction($librarySrc, $symbol, 'qbittorrent.php should no longer export a one-call user bootstrap wrapper');
        $this->assertStringContainsString('template.qbittorrent.conf', $userConfigSrc);
        $this->assertStringContainsString("pmssQbittorrentApplyUploadThrottle(\$user['name'], \$throttle);", $userConfigSrc);
    }

    public function testSuspendLandingKeepsTemplateLoadInline(): void
    {
        $src = $this->pmssReadRepoFile('scripts/suspend.php');
        $symbol = 'pmssRender'.'SuspendedHtml';

        $this->assertSourceOmitsFunction($src, $symbol, 'suspend.php should keep suspended landing template selection inside pmssCreateSuspendedLanding()');
        $this->assertStringContainsString("/etc/seedbox/config/template.suspended.notice.html", $src);
        $this->assertStringContainsString("pmssSuspendedFallbackHtml(\$username)", $src);
        $this->assertStringContainsString("@file_put_contents(\$suspendRoot.'/index.html', \$html)", $src);
    }

    public function testShowTrafficKeepsLocalnetSplitAndBarRenderingInline(): void
    {
        $src = $this->pmssReadRepoFile('scripts/showTraffic.php');
        $splitSymbol = 'pmssShowTrafficSplit'.'LocalnetUser';
        $barSymbol = 'pmssShowTrafficRender'.'Bar';

        $this->assertSourceOmitsFunction($src, $splitSymbol, 'showTraffic.php should reuse shared traffic user-key helpers instead of adding a local splitter');
        $this->assertSourceOmitsFunction($src, $barSymbol, 'showTraffic.php should keep the extended output bar rendering inside pmssShowTrafficMain()');
        $this->assertStringContainsString("pmssListManagedUsersResult(__DIR__.'/listUsers.php')", $src);
        $this->assertStringContainsString('pmssTrafficUserKeyBaseUser($thisUser)', $src);
        $this->assertStringContainsString("str_repeat('#', \$filled)", $src);
        $this->assertStringContainsString("str_repeat('-', 10 - \$filled)", $src);
    }

    public function testStorageToolsShareLsblkDiskInventoryParser(): void
    {
        $snapshotSrc = $this->pmssReadRepoFile('scripts/util/storageHealthSnapshot.php');
        $benchmarkSrc = $this->pmssReadRepoFile('scripts/util/storageBenchmark.php');
        $commonSrc = $this->pmssReadRepoFile('scripts/lib/storageHealth/common.php');
        $libraryPath = dirname(__DIR__, 4).'/scripts/lib/storageHealth/disks.php';
        $facadeSrc = $this->pmssReadRepoFile('scripts/lib/storageHealth.php');
        $symbol = 'pmssStorageHealthDisk'.'InventoryFromLsblk';

        $this->assertTrue(!is_file($libraryPath), 'Expected one-call storageHealth/disks.php helper file to be removed');
        $this->assertStringContainsString('function '.$symbol.'(', $commonSrc);
        $this->assertStringContainsString($symbol.'((string) shell_exec', $snapshotSrc);
        $this->assertStringContainsString($symbol.'((string) shell_exec', $benchmarkSrc);
        $this->pmssAssertStringNotContainsString("preg_split('/\\r?\\n/', trim((string) \$lsblkOut))", $snapshotSrc, 'snapshot should not keep a local lsblk parser');
        $this->pmssAssertStringNotContainsString(
            "'disks'",
            $facadeSrc,
            'storageHealth.php should stop requiring the removed disks.php module'
        );
    }

    public function testStorageHealthSnapshotKeepsJsonAppendsInline(): void
    {
        $snapshotSrc = $this->pmssReadRepoFile('scripts/util/storageHealthSnapshot.php');
        $wrapperNeedle = '$append'.'Json = static function';

        $this->pmssAssertStringNotContainsString(
            $wrapperNeedle,
            $snapshotSrc,
            'storageHealthSnapshot.php should rely on the shared JSONL append helper instead of a local wrapper'
        );
        $this->assertStringContainsString("require_once __DIR__.'/../lib/log.php';", $snapshotSrc);
        $this->assertStringContainsString('$snapshotEntries[] = pmssStorageHealthSnapshotSmart($disk, $last, $timestamp);', $snapshotSrc);
        $this->assertStringContainsString('$snapshotEntries[] = $nvme;', $snapshotSrc);
        $this->assertStringContainsString('$snapshotEntries[] = $raid;', $snapshotSrc);
        $this->assertStringContainsString('foreach ($snapshotEntries as $entry)', $snapshotSrc);
        $this->assertStringContainsString('pmssJsonLineAppend($logPath, $entry)', $snapshotSrc);
    }

    public function testStorageHealthSnapshotUsesSharedCliOptionParser(): void
    {
        $snapshotSrc = $this->pmssReadRepoFile('scripts/util/storageHealthSnapshot.php');
        $helperSymbol = 'pmssStorageHealthSnapshot'.'ParseCli';

        $this->assertSourceOmitsFunction($snapshotSrc, $helperSymbol, 'storageHealthSnapshot.php should use the shared CLI parser without adding a local wrapper');
        $this->assertStringContainsString("require_once __DIR__.'/../lib/cli/optionParser.php';", $snapshotSrc);
        $this->assertStringContainsString('$parsed = pmssParseCliTokens($argv);', $snapshotSrc);
        $this->assertStringContainsString("pmssCliOptionPresent(\$parsed, 'quiet')", $snapshotSrc);
        $this->assertStringContainsString("pmssCliOptionString(\$parsed, 'json', null, \$logPath)", $snapshotSrc);
        $this->pmssAssertStringNotContainsString("array_pad(explode('=', \$arg, 2), 2, null)", $snapshotSrc);
    }

    public function testStorageBenchmarkDropsStandaloneCliConsumeHelper(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/storageBenchmark.php');
        $helperSymbol = 'consume'.'CliValue';

        $this->assertSourceOmitsFunction($src, $helperSymbol, 'storageBenchmark.php should inline CLI option consumption instead of keeping a standalone helper');
        $this->assertStringContainsString("'--device-runtime'", $src);
        $this->assertStringContainsString("'--require-idle'", $src);
    }

    public function testStorageHealthFacadeDropsStandaloneExecModule(): void
    {
        $facadeSrc = $this->pmssReadRepoFile('scripts/lib/storageHealth.php');
        $commonSrc = $this->pmssReadRepoFile('scripts/lib/storageHealth/common.php');
        $libraryPath = dirname(__DIR__, 4).'/scripts/lib/storageHealth/exec.php';
        $symbol = 'pmssStorageHealthExec'.'Capture';

        $this->assertTrue(!is_file($libraryPath), 'Expected one-call storageHealth/exec.php helper file to be removed');
        $this->pmssAssertStringNotContainsString(
            "'exec'",
            $facadeSrc,
            'storageHealth.php should stop requiring the removed exec.php module'
        );
        $this->assertStringContainsString('function '.$symbol.'(', $commonSrc);
        $this->assertStringContainsString('return pmssCommandCapture($cmd, $timeoutSec);', $commonSrc);
    }

    public function testResourceSnapshotCronOwnsSnapshotLoop(): void
    {
        $cronSrc = $this->pmssReadRepoFile('scripts/cron/resourceSnapshot.php');
        $libraryPath = dirname(__DIR__, 4).'/scripts/lib/resources/snapshot.php';
        $symbol = 'pmssResource'.'SnapshotRun';
        $this->assertTrue(!is_file($libraryPath), 'Expected one-call resources snapshot library file to be removed');
        $this->assertStringContainsString("require_once __DIR__.'/../lib/resources/log.php';", $cronSrc);
        $this->assertStringContainsString('const PMSS_RESOURCE_SNAPSHOT_LOG_DEFAULT', $cronSrc);
        $this->assertStringContainsString('readSnapshotMetricsFromPath($dataPath)', $cronSrc);
        $this->assertStringContainsString("collectWindowResultsFromData(\$dataLines, ['day' => \$threshold])", $cronSrc);
        $this->pmssAssertStringNotContainsString('new ResourceStatsAccumulator([\'day\' => $threshold])', $cronSrc);
        $this->assertStringContainsString("pmssRunCliEntrypoint(__FILE__, 'pmssResourceSnapshotRun');", $cronSrc);
        $this->assertStringContainsString('function '.$symbol.'(): int', $cronSrc);
    }

    public function testUpdateLibraryDropsStandaloneSkeletonPathHelper(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update.php');
        $symbol = 'pmssSkeleton'.'Path';

        $this->assertSourceOmitsFunction($src, $symbol, 'update.php should keep skeleton path joins inline inside updateUserFile()');
        $this->assertStringContainsString("pmssSkeletonBase().'/'.\$file", $src);
    }

    public function testUpdateLibraryDropsLegacyFacadeWrappers(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update.php');
        $aptFacade = 'function update'.'AptSources(';
        $motdFacade = 'function generate'.'Motd(';

        $this->pmssAssertStringNotContainsString(
            $aptFacade,
            $src,
            'update.php should keep the canonical pmssUpdateAptSources() symbol only'
        );
        $this->pmssAssertStringNotContainsString(
            $motdFacade,
            $src,
            'update.php should not keep a dead MOTD wrapper once callers use Motd::motdGenerate() directly'
        );
        $this->assertStringContainsString('function getPmssVersion(', $src);
    }

    public function testSkeletonMaintenanceDoesNotInjectTorrentFrontendOperatorRequires(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/users.php');
        $filesystemSrc = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');
        $symbol = 'pmssUserPatch'.'TorrentFrontends';

        $this->assertSourceOmitsFunction($filesystemSrc, $symbol, 'filesystem.php should not restore removed torrent frontend patch wrappers');
        $this->assertStringContainsString("require_once __DIR__.'/users/filesystem.php';", $src);
        $this->pmssAssertStringNotContainsString("require_once '/scripts/lib/user/torrentPort.php';", $filesystemSrc);
        $this->pmssAssertStringNotContainsString("is_readable('/scripts/lib/user/torrentPort.php')", $filesystemSrc);
        $this->pmssAssertStringNotContainsString('pmssDelugePortEnsureCurrentUser', $filesystemSrc);
        $this->pmssAssertStringNotContainsString('pmssQbittorrentPortEnsureCurrentUser', $filesystemSrc);
    }

    public function testUserUpdateModuleOwnsRutorrentHelpers(): void
    {
        $usersSrc = $this->pmssReadRepoFile('scripts/lib/update/users.php');
        $rutorrentSrc = $this->pmssReadRepoFile('scripts/lib/update/users/rutorrent.php');
        $symbol = 'pmssUserUpgrade'.'Rutorrent';

        $this->assertSourceOmitsFunction($usersSrc, $symbol, 'users.php should stay thin and delegate ruTorrent maintenance');
        $this->assertStringContainsString("require_once __DIR__.'/users/rutorrent.php';", $usersSrc);
        $this->assertStringContainsString('function pmssUserUpgradeRutorrent(', $rutorrentSrc);
        $this->assertStringContainsString('function pmssUserMaintainRutorrentPhpCompatibility(', $rutorrentSrc);
        $this->assertStringContainsString('function pmssUserUpdateThemes(', $rutorrentSrc);
    }

    public function testOsReleaseHelpersKeepPathLookupInline(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/osRelease.php');
        $symbol = 'pmssOsRelease'.'Path';

        $this->assertSourceOmitsFunction($src, $symbol, 'osRelease.php should keep the os-release path lookup inline inside cache helpers');
        $this->assertStringContainsString("pmssResolvePathFromEnv('PMSS_OS_RELEASE_PATH', '/etc/os-release')", $src);
    }

    public function testRuntimeProfileKeepsStoreInitializationInsideRecordProfile(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/runtime/profile.php');
        $symbol = 'pmssInit'.'ProfileStore';

        $this->assertSourceOmitsFunction($src, $symbol, 'runtime/profile.php should keep profile-store initialization inside pmssRecordProfile()');
        $this->assertStringContainsString("if (!is_array(\$GLOBALS['PMSS_PROFILE'] ?? null))", $src);
        $this->assertStringContainsString("\$GLOBALS['PMSS_PROFILE'][] = \$entry;", $src);
    }

    public function testUserMaintenanceKeepsOptionalPostChecksInline(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/userMaintenance.php');
        $symbol = 'pmssRunUser'.'PostCheck';

        $this->assertSourceOmitsFunction($src, $symbol, 'userMaintenance.php should keep optional htpasswd/lighttpd checks inside pmssUpdateAllUsers()');
        $this->assertStringContainsString('Synchronizing per-user htpasswd', $src);
        $this->assertStringContainsString('Checking lighttpd instance', $src);
    }

    public function testUserMaintenanceKeepsProfilePayloadLocal(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/userMaintenance.php');
        $symbol = 'pmssBuild'.'UserMaintenanceProfile';

        $this->assertSourceOmitsFunction($src, $symbol, 'userMaintenance.php should keep per-user profile payload assembly inside pmssUpdateAllUsers()');
        $this->assertStringContainsString("'description'    => 'updateUser '.\$user", $src);
        $this->assertStringContainsString("'stdout_excerpt' => ''", $src);
        $this->assertStringContainsString("'stderr_excerpt' => \$stderrExcerpt", $src);
    }

    public function testDockerDependenciesKeepDaemonJsonWritesLocal(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/userMaintenance.php');
        $symbol = 'pmssWrite'.'DockerDaemonConfig';

        $this->assertSourceOmitsFunction($src, $symbol, 'userMaintenance.php should keep daemon.json write handling inside pmssEnsureDockerDependencies()');
        $this->assertStringContainsString('pmssJsonEncodePretty($payload)', $src);
        $this->assertStringContainsString("'native.cgroupdriver=cgroupfs'", $src);
    }

    public function testRepositoryPrerequisitesKeepSonarrDetectionInline(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/repositories.php');
        $symbol = 'pmssSonarr'.'SourceLine';

        $this->assertSourceOmitsFunction($src, $symbol, 'repositories.php should keep Sonarr source detection inside signed-by rewriting');
        $this->assertStringContainsString("preg_match('/^[ \\t]*#/', \$line) === 1", $src);
        $this->assertStringContainsString('signed-by=', $src);
    }

    public function testPluginMaintenanceOwnsRetrackerCleanup(): void
    {
        $usersSrc = $this->pmssReadRepoFile('scripts/lib/update/users.php');
        $pluginsSrc = $this->pmssReadRepoFile('scripts/lib/update/users/rutorrent.php');
        $symbol = 'pmssUserMaintain'.'Retracker';

        $this->assertSourceOmitsFunction($pluginsSrc, $symbol, 'rutorrent.php should keep retracker cleanup inside pmssUserEnsurePlugins()');
        $this->assertStringContainsString("require_once __DIR__.'/users/rutorrent.php';", $usersSrc);
        $this->assertStringContainsString('retrackers.dat', $pluginsSrc);
        $this->assertStringContainsString('Creating ruTorrent RSS settings directory', $pluginsSrc);
    }

    public function testUserUpdateModuleOwnsContextAndHttpHelpers(): void
    {
        $usersSrc = $this->pmssReadRepoFile('scripts/lib/update/users.php');
        $contextSrc = $this->pmssReadRepoFile('scripts/lib/update/users/context.php');
        $httpSrc = $this->pmssReadRepoFile('scripts/lib/update/users/http.php');

        $this->assertSourceOmitsFunction($usersSrc, 'pmssBuildUserContext', 'users.php should delegate context building to a domain module');
        $this->assertSourceOmitsFunction($usersSrc, 'pmssUserConfigureHttp', 'users.php should delegate HTTP maintenance to a domain module');
        $this->assertStringContainsString("require_once __DIR__.'/users/context.php';", $usersSrc);
        $this->assertStringContainsString("require_once __DIR__.'/users/http.php';", $usersSrc);
        $this->assertStringContainsString('function pmssBuildUserContext(', $contextSrc);
        $this->assertStringContainsString('www-disabled', $contextSrc);
        $this->assertStringContainsString('function pmssUserConfigureHttp(', $httpSrc);
        $this->assertStringContainsString('HostHeaderValidation', $httpSrc);
    }

    public function testUserUpdateModuleOwnsPermissionRefreshHelper(): void
    {
        $usersSrc = $this->pmssReadRepoFile('scripts/lib/update/users.php');
        $permissionsSrc = $this->pmssReadRepoFile('scripts/lib/update/users/permissions.php');

        $this->assertSourceOmitsFunction($usersSrc, 'pmssUserRefreshPermissions', 'users.php should delegate permission refresh to permissions.php');
        $this->assertStringContainsString("require_once __DIR__.'/users/permissions.php';", $usersSrc);
        $this->assertStringContainsString('function pmssUserRefreshPermissions(', $permissionsSrc);
        $this->assertStringContainsString('PMSS_USER_PERMISSIONS_TIMEOUT', $permissionsSrc);
        $this->assertStringContainsString("'-c3'", $permissionsSrc);
    }

    public function testUserDomainModulesDoNotCrossRequireEachOther(): void
    {
        foreach (['context', 'http', 'filesystem', 'permissions', 'rutorrent'] as $module) {
            $src = $this->pmssReadRepoFile('scripts/lib/update/users/'.$module.'.php');
            $this->pmssAssertStringNotContainsString(
                "require_once __DIR__.'/",
                $src,
                $module.'.php should not require sibling domain modules'
            );
        }
    }

    public function testUserUpdateEntrypointKeepsDirectHandlerSequence(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/users.php');

        $this->assertStringContainsString("require_once __DIR__.'/../user/log.php';", $src);
        $this->assertStringContainsString("'pmssUserConfigureHttp'", $src);
        $this->assertStringContainsString("'pmssUserApplySkeletonFiles'", $src);
        $this->assertStringContainsString("'pmssUserUpgradeRutorrent'", $src);
        $this->assertStringContainsString("'pmssUserRefreshPermissions'", $src);
        $this->pmssAssertStringNotContainsString(
            'pmssEnsureLingerAndDocker($user)',
            $src,
            'users.php should keep single-user refresh limited to environment handlers'
        );
        $this->pmssAssertStringNotContainsString(
            'Missing handler',
            $src,
            'users.php should not keep a dead missing-handler warning branch once domain modules are required directly'
        );
    }

    public function testUserMaintenanceKeepsDirectPhaseSummaryAndSummaryLogging(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/userMaintenance.php');
        $updatePos = strpos($src, 'pmssUpdateUserEnvironment($userTrim, $rutorrentIndexSha);');
        $lingerPos = strpos($src, 'pmssEnsureLingerAndDocker($userTrim);');
        $lighttpdPostCheckPos = strpos($src, "\$postChecks['Checking lighttpd instance'] = '/scripts/cron/checkLighttpdInstances.php';");
        $postCheckLoopPos = strpos($src, 'foreach ($postChecks as $label => $helperPath)');

        $this->assertStringContainsString('Environment (HTTP/ruTorrent/permissions + linger/systemd/rootless Docker)', $src);
        $this->assertTrue($updatePos !== false, 'userMaintenance.php should update the user environment directly');
        $this->assertTrue($lingerPos !== false, 'userMaintenance.php should keep linger wiring in the main per-user loop');
        $this->assertTrue($lighttpdPostCheckPos !== false, 'userMaintenance.php should keep the per-user lighttpd watchdog post-check');
        $this->assertTrue($postCheckLoopPos !== false, 'userMaintenance.php should run post-check helpers after environment convergence');
        $this->assertTrue($lingerPos > $updatePos, 'linger wiring should run after environment convergence');
        $this->assertTrue($postCheckLoopPos > $updatePos, 'lighttpd post-checks must run after custom.d fragments are written');
        $this->assertTrue($postCheckLoopPos > $lingerPos, 'lighttpd post-checks should run after linger wiring in the per-user loop');
        $this->assertStringContainsString("pmssUserLog(\$userTrim, '[WARN] update-step2 user maintenance aborted: '.\$reason);", $src);
        $this->assertStringContainsString('pmssLogJson([', $src);
        $this->pmssAssertStringNotContainsString(
            "function_exists('pmssUpdateUserEnvironment')",
            $src,
            'userMaintenance.php should not guard helpers that are required at file load time'
        );
        $this->pmssAssertStringNotContainsString(
            "function_exists('pmssLogJson')",
            $src,
            'userMaintenance.php should log its JSON summary directly through the required logging runtime'
        );
        $this->pmssAssertStringNotContainsString(
            "function_exists('pmssUserDockerEnabled')",
            $src,
            'userMaintenance.php should call the required Docker config helper directly'
        );
        foreach ([
            "if (!function_exists('pmssRunAndLog'))",
            "if (!function_exists('pmssUpdateAllUsers'))",
            "if (!function_exists('pmssEnsureLingerAndDocker'))",
            "if (!function_exists('pmssEnsureRootlessDockerInstalled'))",
            "if (!function_exists('pmssEnsureDockerDependencies'))",
        ] as $deadGuard) {
            $this->pmssAssertStringNotContainsString(
                $deadGuard,
                $src,
                'userMaintenance.php should not keep dead self-guard wrappers once runtime callers use require_once'
            );
        }
    }

    public function testDistUpgradeUsesRequiredRepairHelpersDirectly(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/distUpgrade.php');

        $this->assertStringContainsString('pmssEnsureBootDefaults(', $src);
        $this->assertStringContainsString('pmssEnsureRootlessDockerInstalled($user);', $src);
        $this->assertStringContainsString('pmssEnsureDockerDependencies($user);', $src);
        $this->assertStringContainsString("pmssUserLog(\$userTrim, '[SKIP] dist-upgrade: user appears suspended; skipping rootless Docker repair');", $src);
        $this->assertStringContainsString("pmssUserLog(\$user, 'dist-upgrade: rootless Docker repair start');", $src);
        $this->pmssAssertStringNotContainsString(
            "function_exists('pmssEnsureBootDefaults')",
            $src,
            'distUpgrade.php should call the required boot defaults helper directly'
        );
        $this->pmssAssertStringNotContainsString(
            'class_exists(\'users\')',
            $src,
            'distUpgrade.php should not keep a dead users class guard once userMaintenance.php is required'
        );
        $this->pmssAssertStringNotContainsString(
            "function_exists('pmssEnsureRootlessDockerInstalled')",
            $src,
            'distUpgrade.php should not keep dead rootless helper guards once userMaintenance.php is required'
        );
        $this->pmssAssertStringNotContainsString(
            "function_exists('pmssUserLog')",
            $src,
            'distUpgrade.php should log through the required user logger directly'
        );
    }
}
