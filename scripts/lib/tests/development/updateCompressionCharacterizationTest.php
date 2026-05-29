<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateCompressionCharacterizationTest extends TestCase
{
    public function testStartRtorrentReusesSharedProcessLookups(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/startRtorrent', [
            "rtorrentProcessPgrepExact(\$user, 'rtorrent', \$rtorrentRc, \$rtorrentOut)",
            "rtorrentProcessExecutorPids(\$user, \$executorRc, \$executorOut)['all']",
        ]);
    }

    public function testUpdateStep2KeepsInlineLighttpdHardeningStep(): void
    {
        $symbol = 'pmssAdjust'.'LighttpdSecurity';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/update-step2.php', [
            "pmssRunProfiledStep('Adjusting lighttpd security settings'",
            "runStep('Restricting /etc/lighttpd directory permissions', 'chmod 750 /etc/lighttpd');",
            "logmsg('[SKIP] lighttpd .htpasswd missing; per-user instances manage authentication');",
        ], [$symbol => 'update-step2.php should own the lighttpd hardening block directly']);
    }

    public function testAptSourcesDebianSelectionUsesSharedReleaseSpecs(): void
    {
        $legacyTable = 'static $targets = [';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apt.php', [
            "require_once __DIR__.'/distro.php';",
            'pmssDebianReleaseSpecs()[$version] ?? null',
        ], [$legacyTable => 'apt.php should not keep a second Debian release target table']);
    }

    public function testNginxSubdomainTemplateOutputSnapshot(): void
    {
        require_once dirname(__DIR__, 3).'/lib/nginxConfig/templates.php';
        $this->assertSame('86ea6ac663b4a48a25b6a8ad4718787cb671dc376bb121bcdd3aa21c51480c8d', hash('sha256', implode("\n---PMSS-TEMPLATE---\n", \pmssNginxUserSubdomainTemplates())), 'nginx subdomain template output changed');
    }

    public function testUpdateStep2OwnsWebStackConfiguration(): void
    {
        $this->assertTrue(!is_file($this->pmssRepoPath('scripts/lib/update/webStack.php')), 'Expected one-call update/webStack.php helper file to be removed');
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/update-step2.php', [
            'function pmssConfigureWebStack(): void',
            "runStep('Stopping nginx prior to configuration refresh'",
            "pmssSystemdUnitActionIfPresent('lighttpd', 'Disabling lighttpd systemd service', 'disable');",
            "if (\$reportedVersion > 0 && \$reportedVersion < 10)",
        ], [
            "require_once __DIR__.'/../lib/update/webStack.php';" => 'update-step2.php should stop requiring the removed webStack.php module',
            'update-rc.d lighttpd' => 'update-step2.php aborts unsupported Debian versions before web-stack configuration, so legacy sysvinit branches should stay removed',
        ]);
    }

    public function testKillProcessKeepsGracefulAndForcedWaitPhasesLocally(): void
    {
        $symbol = 'pmssWaitFor'.'ProcessExit';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/runtime/processes.php', [
            "foreach (['TERM' => max(0, \$timeoutSeconds), 'KILL' => 5] as \$signal => \$waitSeconds)",
            "runStep(\$description.' (SIG'.\$signal.')'",
            'graceful stop',
            'processes linger after SIGKILL',
        ], ['function '.$symbol.'(' => 'process wait logic should be localized inside killProcess()']);
    }

    public function testKillProcessKeepsProcessProbeLocal(): void
    {
        $symbol = 'pmssProcess'.'Running';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/runtime/processes.php', [
            "\$probeCommand = 'pgrep -x '.escapeshellarg(\$name).' >/dev/null 2>&1';",
            'exec($probeCommand, $_, $probeStatus);',
            '[SKIP] {$description} (no {$name} processes)',
        ], ['function '.$symbol.'(' => 'process presence checks should stay localized inside killProcess()']);
    }

    public function testKillProcessKeepsLocalWaitLoopsInlineWithoutClosures(): void
    {
        $probeNeedle = '$process'.'Running = static function';
        $waitNeedle = '$waitFor'.'ProcessExit = static function';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/runtime/processes.php', [
            '$deadline = microtime(true) + $waitSeconds;',
            'usleep(250000);',
        ], [
            $probeNeedle => 'killProcess() should keep the process probe inline without a local closure',
            $waitNeedle => 'killProcess() should keep the wait loops inline without a local closure',
        ]);
    }

    public function testUpdateStep2KeepsMediaareaBootstrapCleanupInline(): void
    {
        $symbol = 'pmssCleanup'.'MediaareaBootstrapPackage';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/update-step2.php', [
            "pmssRunProfiledStep('Cleaning mediaarea bootstrap package state'",
            "dpkg-query -W -f=\${Status} repo-mediaarea 2>/dev/null",
            "runStep('Marking repo-mediaarea for deinstallation', \$setSelection);",
        ], [$symbol => 'update-step2.php should own the mediaarea bootstrap cleanup directly']);
    }

    public function testRootlessDockerUnitParsingBelongsToDockerMaintenanceModule(): void
    {
        $symbol = 'pmssReadSystemd'.'UnitExecStartBinary';

        $this->pmssAssertRepoFileContainsString('scripts/lib/update/userMaintenance.php', "require_once __DIR__.'/users/docker.php';");
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/users/docker.php', [
            'function pmssUserDockerUnitExecBinary(',
            "if (\$trim === '' || strpos(\$trim, 'ExecStart=') !== 0)",
            "return \$parts && \$parts[0] !== '' ? trim(\$parts[0], \"\\\"'\") : null;",
        ], ['function '.$symbol.'(' => 'docker.php should own rootless Docker unit parsing directly']);
        $this->pmssAssertRepoFileNotContainsString(
            'scripts/lib/update/userMaintenance.php',
            "ExecStart=",
            'userMaintenance.php should not parse rootless Docker units'
        );
    }

    public function testSystemdDropinInstallerKeepsSingleFailurePrefix(): void
    {
        $removedHelper = 'pmssSystemdDropin'.'Install';
        $deadPrefixSymbol = '$write'.'FailurePrefix';
        $deadTempPrefixMessage = 'Failed to write'.' temp';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/systemPrep/systemdSlicesEnsure.php', [
            "pmssWriteManagedPathFile(\$target, \$raw, 'systemd drop-in'",
            "pmssWriteManagedPathFile(\$userAtTarget, \$userAtBody, 'systemd drop-in'",
        ], [
            'function '.$removedHelper.'(' => 'systemd drop-in writes should use the shared managed-file writer directly',
            $deadPrefixSymbol => 'systemd drop-in writes should not keep a dead temp-write prefix concept',
            $deadTempPrefixMessage => 'systemd drop-in callers should log the single managed-write failure path',
        ]);
    }

    public function testUpdateLoggingKeepsCorrelationIdBuildLocal(): void
    {
        $symbol = 'pmssBuild'.'CorrelationId';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/logging.php', [
            "gmdate('Ymd-His')",
            "bin2hex(random_bytes(3))",
            "substr(hash('sha256', \$timestamp.\$host.microtime(true)), 0, 6)",
        ], ['function '.$symbol.'(' => 'logging.php should keep correlation ID generation inside pmssCorrelationId()']);
    }

    public function testBootstrapUpdateKeepsCorrelationIdBuildLocal(): void
    {
        $symbol = 'pmssBuild'.'CorrelationId';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/update.php', [
            "bin2hex(random_bytes(3))",
            "PMSS_CORRELATION_ENV.'='.\$generated",
        ], ['function '.$symbol.'(' => 'update.php should keep correlation ID generation inside pmssCorrelationId()']);
    }

    public function testQuotaSnapshotKeepsSizeTokenNormalizationLocal(): void
    {
        $symbol = 'pmssQuotaSnapshotNormalize'.'SizeToken';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/quotaSnapshot.php', [
            "preg_match('/^([0-9]+)(\\*)?$/', \$tokens[\$index], \$matches)",
            "\$tokens[\$index] = \$matches[1].'K'.(\$matches[2] ?? '');",
        ], ['function '.$symbol.'(' => 'quotaSnapshot.php should keep bare-size token normalization inside the line normalizer']);
    }

    public function testQbittorrentPortEnsureUsesSharedConfigWriter(): void
    {
        $symbol = 'pmssTorrentPort'.'FileWrite';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/torrentPort.php', [
            "require_once __DIR__.'/qbittorrent.php';",
            'pmssQbittorrentConfigMutate(',
        ], [
            'function '.$symbol.'(' => 'torrentPort.php should not grow a second qBittorrent file-writer helper',
            '@tempnam($dir, basename($configPath).\'.pmss-tmp-\')' => 'qBittorrent port repair should use the shared config writer instead of an inline temp-file path',
        ]);
    }

    public function testUserConfigKeepsQbittorrentBootstrapInline(): void
    {
        $symbol = 'userConfigure'.'Qbittorrent';

        $this->pmssAssertRepoFileNotContainsFunction('scripts/lib/user/qbittorrent.php', $symbol, 'qbittorrent.php should no longer export a one-call user bootstrap wrapper');
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', [
            'template.qbittorrent.conf',
            "pmssQbittorrentApplyUploadThrottle(\$user['name'], \$throttle);",
        ]);
    }

    public function testSuspendLandingKeepsTemplateLoadInline(): void
    {
        $symbol = 'pmssRender'.'SuspendedHtml';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/suspend.php', [
            "/etc/seedbox/config/template.suspended.notice.html",
            "pmssSuspendedFallbackHtml(\$username)",
            "@file_put_contents(\$suspendRoot.'/index.html', \$html)",
        ], ['function '.$symbol.'(' => 'suspend.php should keep suspended landing template selection inside pmssCreateSuspendedLanding()']);
    }

    public function testShowTrafficKeepsLocalnetSplitAndBarRenderingInline(): void
    {
        $splitSymbol = 'pmssShowTrafficSplit'.'LocalnetUser';
        $barSymbol = 'pmssShowTrafficRender'.'Bar';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/showTraffic.php', [
            "pmssListManagedUsersResult(__DIR__.'/listUsers.php')",
            'pmssTrafficUserKeyBaseUser($thisUser)',
            "str_repeat('#', \$filled)",
            "str_repeat('-', 10 - \$filled)",
        ], [
            'function '.$splitSymbol.'(' => 'showTraffic.php should reuse shared traffic user-key helpers instead of adding a local splitter',
            'function '.$barSymbol.'(' => 'showTraffic.php should keep the extended output bar rendering inside pmssShowTrafficMain()',
        ]);
    }

    public function testStorageToolsShareLsblkDiskInventoryParser(): void
    {
        $symbol = 'pmssStorageHealthDisk'.'InventoryFromLsblk';

        $this->assertTrue(!is_file($this->pmssRepoPath('scripts/lib/storageHealth/disks.php')), 'Expected one-call storageHealth/disks.php helper file to be removed');
        $this->pmssAssertRepoFileContainsString('scripts/lib/storageHealth/common.php', 'function '.$symbol.'(');
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/storageHealthSnapshot.php', [
            $symbol.'((string) shell_exec',
        ], [
            "preg_split('/\\r?\\n/', trim((string) \$lsblkOut))" => 'snapshot should not keep a local lsblk parser',
        ]);
        $this->pmssAssertRepoFileContainsString('scripts/util/storageBenchmark.php', $symbol.'((string) shell_exec');
        $this->pmssAssertRepoFileNotContainsString('scripts/lib/storageHealth.php', "'disks'", 'storageHealth.php should stop requiring the removed disks.php module');
    }

    public function testStorageHealthSnapshotKeepsJsonAppendsInline(): void
    {
        $wrapperNeedle = '$append'.'Json = static function';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/storageHealthSnapshot.php', [
            "require_once __DIR__.'/../lib/log.php';",
            '$snapshotEntries[] = pmssStorageHealthSnapshotSmart($disk, $last, $timestamp);',
            '$snapshotEntries[] = $nvme;',
            '$snapshotEntries[] = $raid;',
            'foreach ($snapshotEntries as $entry)',
            'pmssJsonLineAppend($logPath, $entry)',
        ], [$wrapperNeedle => 'storageHealthSnapshot.php should rely on the shared JSONL append helper instead of a local wrapper']);
    }

    public function testStorageHealthSnapshotUsesSharedCliOptionParser(): void
    {
        $helperSymbol = 'pmssStorageHealthSnapshot'.'ParseCli';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/storageHealthSnapshot.php', [
            "require_once __DIR__.'/../lib/cli/optionParser.php';",
            '$parsed = pmssParseCliTokens($argv);',
            "pmssCliOptionPresent(\$parsed, 'quiet')",
            "pmssCliOptionString(\$parsed, 'json', null, \$logPath)",
        ], [
            'function '.$helperSymbol.'(' => 'storageHealthSnapshot.php should use the shared CLI parser without adding a local wrapper',
            "array_pad(explode('=', \$arg, 2), 2, null)",
        ]);
    }

    public function testStorageBenchmarkDropsStandaloneCliConsumeHelper(): void
    {
        $helperSymbol = 'consume'.'CliValue';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/storageBenchmark.php', [
            "'--device-runtime'",
            "'--require-idle'",
        ], ['function '.$helperSymbol.'(' => 'storageBenchmark.php should inline CLI option consumption instead of keeping a standalone helper']);
    }

    public function testStorageHealthFacadeDropsStandaloneExecModule(): void
    {
        $symbol = 'pmssStorageHealthExec'.'Capture';

        $this->assertTrue(!is_file($this->pmssRepoPath('scripts/lib/storageHealth/exec.php')), 'Expected one-call storageHealth/exec.php helper file to be removed');
        $this->pmssAssertRepoFileNotContainsString('scripts/lib/storageHealth.php', "'exec'", 'storageHealth.php should stop requiring the removed exec.php module');
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/storageHealth/common.php', [
            'function '.$symbol.'(',
            'return pmssCommandCapture($cmd, $timeoutSec);',
        ]);
    }

    public function testResourceSnapshotCronOwnsSnapshotLoop(): void
    {
        $symbol = 'pmssResource'.'SnapshotRun';
        $this->assertTrue(!is_file($this->pmssRepoPath('scripts/lib/resources/snapshot.php')), 'Expected one-call resources snapshot library file to be removed');
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/cron/resourceSnapshot.php', [
            "require_once __DIR__.'/../lib/resources/log.php';",
            'const PMSS_RESOURCE_SNAPSHOT_LOG_DEFAULT',
            'readSnapshotMetricsFromPath($dataPath)',
            "collectWindowResultsFromData(\$dataLines, ['day' => \$threshold])",
            "pmssRunCliEntrypoint(__FILE__, 'pmssResourceSnapshotRun');",
            'function '.$symbol.'(): int',
        ], ['new ResourceStatsAccumulator([\'day\' => $threshold])']);
    }

    public function testUpdateLibraryDropsStandaloneSkeletonPathHelper(): void
    {
        $symbol = 'pmssSkeleton'.'Path';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update.php', [
            "pmssSkeletonBase().'/'.\$file",
        ], ['function '.$symbol.'(' => 'update.php should keep skeleton path joins inline inside updateUserFile()']);
    }

    public function testUpdateLibraryDropsLegacyFacadeWrappers(): void
    {
        $aptFacade = 'function update'.'AptSources(';
        $motdFacade = 'function generate'.'Motd(';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update.php', [
            "require_once __DIR__.'/version.php';",
        ], [
            $aptFacade => 'update.php should keep the canonical pmssUpdateAptSources() symbol only',
            $motdFacade => 'update.php should not keep a dead MOTD wrapper once callers use Motd::motdGenerate() directly',
        ]);
        $this->pmssAssertRepoFileContainsString('scripts/lib/version.php', 'function getPmssVersion(');
    }

    public function testSkeletonMaintenanceDoesNotInjectTorrentFrontendOperatorRequires(): void
    {
        $symbol = 'pmssUserPatch'.'TorrentFrontends';

        $this->pmssAssertRepoFileContainsString('scripts/lib/update/users.php', "require_once __DIR__.'/users/filesystem.php';");
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/users/filesystem.php', [], [
            'function '.$symbol.'(' => 'filesystem.php should not restore removed torrent frontend patch wrappers',
            "require_once '/scripts/lib/user/torrentPort.php';",
            "is_readable('/scripts/lib/user/torrentPort.php')",
            'pmssDelugePortEnsureCurrentUser',
            'pmssQbittorrentPortEnsureCurrentUser',
        ]);
    }

    public function testUserUpdateModuleOwnsRutorrentHelpers(): void
    {
        $symbol = 'pmssUserUpgrade'.'Rutorrent';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/users.php', [
            "require_once __DIR__.'/users/rutorrent.php';",
        ], ['function '.$symbol.'(' => 'users.php should stay thin and delegate ruTorrent maintenance']);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/rutorrent.php', [
            'function pmssUserUpgradeRutorrent(',
            'function pmssUserMaintainRutorrentPhpCompatibility(',
            'function pmssUserUpdateThemes(',
        ]);
    }

    public function testOsReleaseHelpersKeepPathLookupInline(): void
    {
        $symbol = 'pmssOsRelease'.'Path';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/osRelease.php', [
            "pmssResolvePathFromEnv('PMSS_OS_RELEASE_PATH', '/etc/os-release')",
        ], ['function '.$symbol.'(' => 'osRelease.php should keep the os-release path lookup inline inside cache helpers']);
    }

    public function testRuntimeProfileKeepsStoreInitializationInsideRecordProfile(): void
    {
        $symbol = 'pmssInit'.'ProfileStore';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/runtime/profile.php', [
            "if (!is_array(\$GLOBALS['PMSS_PROFILE'] ?? null))",
            "\$GLOBALS['PMSS_PROFILE'][] = \$entry;",
        ], ['function '.$symbol.'(' => 'runtime/profile.php should keep profile-store initialization inside pmssRecordProfile()']);
    }

    public function testUserMaintenanceKeepsOptionalPostChecksInline(): void
    {
        $symbol = 'pmssRunUser'.'PostCheck';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/userMaintenance.php', [
            'Synchronizing per-user htpasswd',
            'Checking lighttpd instance',
        ], ['function '.$symbol.'(' => 'userMaintenance.php should keep optional htpasswd/lighttpd checks inside pmssUpdateAllUsers()']);
    }

    public function testUserMaintenanceKeepsProfilePayloadLocal(): void
    {
        $symbol = 'pmssBuild'.'UserMaintenanceProfile';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/userMaintenance.php', [
            "'description'    => 'updateUser '.\$user",
            "'stdout_excerpt' => ''",
            "'stderr_excerpt' => \$stderrExcerpt",
        ], ['function '.$symbol.'(' => 'userMaintenance.php should keep per-user profile payload assembly inside pmssUpdateAllUsers()']);
    }

    public function testDockerDependenciesUseSharedDaemonJsonConvergence(): void
    {
        $symbol = 'pmssWrite'.'DockerDaemonConfig';

        $this->pmssAssertRepoFileContainsString('scripts/lib/update/userMaintenance.php', "require_once __DIR__.'/users/docker.php';");
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/users/docker.php', [
            "require_once dirname(__DIR__, 2).'/user/rootlessDockerConfig.php';",
            "pmssUserRootlessDockerConfigConverge(\$user, \$home, (int) \$uinfo['uid'], (int) \$uinfo['gid']",
        ], [
            'function '.$symbol.'(' => 'daemon.json convergence should not grow a second local writer',
            'pmssJsonEncodePretty($payload)' => 'docker.php should use the shared rootless Docker config writer',
        ]);
    }

    public function testRepositoryPrerequisitesKeepSonarrDetectionInline(): void
    {
        $symbol = 'pmssSonarr'.'SourceLine';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/repositories.php', [
            "preg_match('/^[ \\t]*#/', \$line) === 1",
            'signed-by=',
        ], ['function '.$symbol.'(' => 'repositories.php should keep Sonarr source detection inside signed-by rewriting']);
    }

    public function testPluginMaintenanceOwnsRetrackerCleanup(): void
    {
        $symbol = 'pmssUserMaintain'.'Retracker';

        $this->pmssAssertRepoFileContainsString('scripts/lib/update/users.php', "require_once __DIR__.'/users/rutorrent.php';");
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/users/rutorrent.php', [
            'retrackers.dat',
            'Creating ruTorrent RSS settings directory',
        ], ['function '.$symbol.'(' => 'rutorrent.php should keep retracker cleanup inside pmssUserEnsurePlugins()']);
    }

    public function testUserUpdateModuleOwnsContextAndHttpHelpers(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/users.php', [
            "require_once __DIR__.'/users/context.php';",
            "require_once __DIR__.'/users/http.php';",
        ], [
            'function pmssBuildUserContext(' => 'users.php should delegate context building to a domain module',
            'function pmssUserConfigureHttp(' => 'users.php should delegate HTTP maintenance to a domain module',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/context.php', [
            'function pmssBuildUserContext(',
            'www-disabled',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/http.php', [
            'function pmssUserConfigureHttp(',
            'HostHeaderValidation',
        ]);
    }

    public function testUserUpdateModuleOwnsPermissionRefreshHelper(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/users.php', [
            "require_once __DIR__.'/users/permissions.php';",
        ], ['function pmssUserRefreshPermissions(' => 'users.php should delegate permission refresh to permissions.php']);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/permissions.php', [
            'function pmssUserRefreshPermissions(',
            'PMSS_USER_PERMISSIONS_TIMEOUT',
            "'-c3'",
        ]);
    }

    public function testUserDomainModulesDoNotCrossRequireEachOther(): void
    {
        foreach (['context', 'http', 'filesystem', 'permissions', 'rutorrent', 'docker'] as $module) {
            $this->pmssAssertRepoFileNotContainsString(
                'scripts/lib/update/users/'.$module.'.php',
                "require_once __DIR__.'/",
                $module.'.php should not require sibling domain modules'
            );
        }
    }

    public function testUserUpdateEntrypointKeepsDirectHandlerSequence(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/users.php', [
            "require_once __DIR__.'/../user/log.php';",
            "'pmssUserConfigureHttp'",
            "'pmssUserApplySkeletonFiles'",
            "'pmssUserUpgradeRutorrent'",
            "'pmssUserRefreshPermissions'",
        ], [
            'pmssEnsureLingerAndDocker($user)' => 'users.php should keep single-user refresh limited to environment handlers',
            'Missing handler' => 'users.php should not keep a dead missing-handler warning branch once domain modules are required directly',
        ]);
    }

    public function testUserMaintenanceKeepsDirectPhaseSummaryAndSummaryLogging(): void
    {
        $deadGuards = [
            "if (!function_exists('pmssRunAndLog'))",
            "if (!function_exists('pmssUpdateAllUsers'))",
            "if (!function_exists('pmssEnsureLingerAndDocker'))",
            "if (!function_exists('pmssEnsureRootlessDockerInstalled'))",
            "if (!function_exists('pmssEnsureDockerDependencies'))",
        ];

        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/update/userMaintenance.php', [
            'pmssUpdateUserEnvironment($userTrim, $rutorrentIndexSha);',
            'pmssEnsureLingerAndDocker($userTrim);',
            'foreach ($postChecks as $label => $helperPath)',
        ], 'Missing user-maintenance phase: ', 'User-maintenance phase order changed at: ');
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/userMaintenance.php', [
            'Environment (HTTP/ruTorrent/permissions + linger/systemd/rootless Docker)',
            "require_once __DIR__.'/users/docker.php';",
            "\$postChecks['Checking lighttpd instance'] = '/scripts/cron/checkLighttpdInstances.php';",
            "pmssUserLog(\$userTrim, '[WARN] update-step2 user maintenance aborted: '.\$reason);",
            'pmssLogJson([',
        ], array_merge([
            "function_exists('pmssUpdateUserEnvironment')" => 'userMaintenance.php should not guard helpers that are required at file load time',
            "function_exists('pmssLogJson')" => 'userMaintenance.php should log its JSON summary directly through the required logging runtime',
            "function_exists('pmssUserDockerEnabled')" => 'userMaintenance.php should call the required Docker config helper directly',
        ], array_fill_keys($deadGuards, 'userMaintenance.php should not keep dead self-guard wrappers once runtime callers use require_once')));
    }

    public function testDistUpgradeUsesRequiredRepairHelpersDirectly(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/distUpgrade.php', [
            'pmssEnsureBootDefaults(',
            'pmssEnsureRootlessDockerInstalled($user);',
            'pmssEnsureDockerDependencies($user);',
            "pmssUserLog(\$userTrim, '[SKIP] dist-upgrade: user appears suspended; skipping rootless Docker repair');",
            "pmssUserLog(\$user, 'dist-upgrade: rootless Docker repair start');",
        ], [
            "function_exists('pmssEnsureBootDefaults')" => 'distUpgrade.php should call the required boot defaults helper directly',
            'class_exists(\'users\')' => 'distUpgrade.php should not keep a dead users class guard once userMaintenance.php is required',
            "function_exists('pmssEnsureRootlessDockerInstalled')" => 'distUpgrade.php should not keep dead rootless helper guards once userMaintenance.php is required',
            "function_exists('pmssUserLog')" => 'distUpgrade.php should log through the required user logger directly',
        ]);
    }
}
