<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateCompressionCharacterizationTest extends TestCase
{
    public function testStartRtorrentReusesSharedProcessLookups(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/startRtorrent', [
            "pmssUserWatchdogProcessPids(\$user, '^rtorrent', [], \$rtorrentRc, \$rtorrentOut)",
            "rtorrentProcessExecutorPids(\$user, \$executorRc, \$executorOut)['all']",
        ]);
        $this->pmssAssertRepoFileNotContainsString('scripts/lib/rtorrent/process.php', 'function rtorrentProcess'.'PgrepExact(');
    }

    public function testInlineHelperCompressionContractsStayLocal(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/update-step2.php' => [
                'required' => [
                    "pmssRunProfiledStep('Adjusting lighttpd security settings'",
                    "runStep('Restricting /etc/lighttpd directory permissions', 'chmod 750 /etc/lighttpd');",
                    "logmsg('[SKIP] lighttpd .htpasswd missing; per-user instances manage authentication');",
                    "pmssRunProfiledStep('Cleaning mediaarea bootstrap package state'",
                    "dpkg-query -W -f=\${Status} repo-mediaarea 2>/dev/null",
                    "runStep('Marking repo-mediaarea for deinstallation', \$setSelection);",
                ],
                'forbidden' => [
                    'pmssAdjust'.'LighttpdSecurity' => 'update-step2.php should own the lighttpd hardening block directly',
                    'pmssCleanup'.'MediaareaBootstrapPackage' => 'update-step2.php should own the mediaarea bootstrap cleanup directly',
                ],
            ],
            'scripts/lib/update/apt.php' => [
                'required' => [
                    "require_once __DIR__.'/distro.php';",
                    'pmssDebianReleaseSpecs()[$version] ?? null',
                ],
                'forbidden' => [
                    'static $targets = [' => 'apt.php should not keep a second Debian release target table',
                ],
            ],
            'scripts/lib/update/runtime/processes.php' => [
                'required' => [
                    "foreach (['TERM' => max(0, \$timeoutSeconds), 'KILL' => 5] as \$signal => \$waitSeconds)",
                    "runStep(\$description.' (SIG'.\$signal.')'",
                    'graceful stop',
                    'processes linger after SIGKILL',
                    "\$probeCommand = 'pgrep -x '.escapeshellarg(\$name).' >/dev/null 2>&1';",
                    'exec($probeCommand, $_, $probeStatus);',
                    '[SKIP] {$description} (no {$name} processes)',
                    '$deadline = microtime(true) + $waitSeconds;',
                    'usleep(250000);',
                ],
                'forbidden' => [
                    'function pmssWaitFor'.'ProcessExit(' => 'process wait logic should be localized inside killProcess()',
                    'function pmssProcess'.'Running(' => 'process presence checks should stay localized inside killProcess()',
                    '$process'.'Running = static function' => 'killProcess() should keep the process probe inline without a local closure',
                    '$waitFor'.'ProcessExit = static function' => 'killProcess() should keep the wait loops inline without a local closure',
                ],
            ],
            'scripts/lib/update/systemPrep/systemdSlicesEnsure.php' => [
                'required' => [
                    "pmssWriteManagedPathFile(\$target, \$raw, 'systemd drop-in'",
                    "pmssWriteManagedPathFile(\$userAtTarget, \$userAtBody, 'systemd drop-in'",
                ],
                'forbidden' => [
                    'function pmssSystemdDropin'.'Install(' => 'systemd drop-in writes should use the shared managed-file writer directly',
                    '$write'.'FailurePrefix' => 'systemd drop-in writes should not keep a dead temp-write prefix concept',
                    'Failed to write'.' temp' => 'systemd drop-in callers should log the single managed-write failure path',
                ],
            ],
            'scripts/lib/update/logging.php' => [
                'required' => [
                    "gmdate('Ymd-His')",
                    "bin2hex(random_bytes(3))",
                    "substr(hash('sha256', \$timestamp.\$host.microtime(true)), 0, 6)",
                ],
                'forbidden' => [
                    'function pmssBuild'.'CorrelationId(' => 'logging.php should keep correlation ID generation inside pmssCorrelationId()',
                ],
            ],
            'scripts/update.php' => [
                'required' => [
                    "bin2hex(random_bytes(3))",
                    "PMSS_CORRELATION_ENV.'='.\$generated",
                ],
                'forbidden' => [
                    'function pmssBuild'.'CorrelationId(' => 'update.php should keep correlation ID generation inside pmssCorrelationId()',
                ],
            ],
            'scripts/lib/quotaSnapshot.php' => [
                'required' => [
                    "preg_match('/^([0-9]+)(\\*)?$/', \$tokens[\$index], \$matches)",
                    "\$tokens[\$index] = \$matches[1].'K'.(\$matches[2] ?? '');",
                ],
                'forbidden' => [
                    'function pmssQuotaSnapshotNormalize'.'SizeToken(' => 'quotaSnapshot.php should keep bare-size token normalization inside the line normalizer',
                ],
            ],
            'scripts/lib/user/torrentPort.php' => [
                'required' => [
                    "require_once __DIR__.'/qbittorrent.php';",
                    'pmssQbittorrentConfigMutate(',
                ],
                'forbidden' => [
                    'function pmssTorrentPort'.'FileWrite(' => 'torrentPort.php should not grow a second qBittorrent file-writer helper',
                    '@tempnam($dir, basename($configPath).\'.pmss-tmp-\')' => 'qBittorrent port repair should use the shared config writer instead of an inline temp-file path',
                ],
            ],
            'scripts/suspend.php' => [
                'required' => [
                    "/etc/seedbox/config/template.suspended.notice.html",
                    "pmssSuspendedFallbackHtml(\$username)",
                    "@file_put_contents(\$suspendRoot.'/index.html', \$html)",
                ],
                'forbidden' => [
                    'function pmssRender'.'SuspendedHtml(' => 'suspend.php should keep suspended landing template selection inside pmssCreateSuspendedLanding()',
                ],
            ],
        ]);
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

    public function testRootlessDockerUnitParsingBelongsToDockerMaintenanceModule(): void
    {
        $symbol = 'pmssReadSystemd'.'UnitExecStartBinary';

        $this->pmssAssertRepoFileContainsString('scripts/lib/update/userMaintenance.php', "'users/docker.php'");
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

    public function testUserConfigKeepsQbittorrentBootstrapInline(): void
    {
        $symbol = 'userConfigure'.'Qbittorrent';

        $this->pmssAssertRepoFileNotContainsFunction('scripts/lib/user/qbittorrent.php', $symbol, 'qbittorrent.php should no longer export a one-call user bootstrap wrapper');
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', [
            'template.qbittorrent.conf',
            "pmssQbittorrentApplyUploadThrottle(\$user['name'], \$throttle);",
        ]);
    }

    public function testShowTrafficKeepsLocalnetSplitAndBarRenderingInline(): void
    {
        $splitSymbol = 'pmssShowTrafficSplit'.'LocalnetUser';
        $barSymbol = 'pmssShowTrafficRender'.'Bar';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/traffic/report.php', [
            'pmssListManagedUsersResult($listUsersScript)',
            'pmssTrafficUserKeyBaseUser($thisUser)',
            "str_repeat('#', \$filled)",
            "str_repeat('-', 10 - \$filled)",
        ], [
            'function '.$splitSymbol.'(' => 'showTraffic.php should reuse shared traffic user-key helpers instead of adding a local splitter',
            'function '.$barSymbol.'(' => 'showTraffic.php should keep the extended output bar rendering in the shared report helper',
        ]);
    }

    public function testStorageToolsShareLsblkDiskInventoryParser(): void
    {
        $symbol = 'pmssStorageHealthDisk'.'InventoryFromLsblk';

        $this->assertTrue(!is_file($this->pmssRepoPath('scripts/lib/storageHealth/disks.php')), 'Expected one-call storageHealth/disks.php helper file to be removed');
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/storageHealth/common.php', [
            'function '.$symbol.'(',
            'function pmssStorageHealthDiskInventoryRead(): array',
            'return '.$symbol."((string) (\$result['stdout'] ?? ''));",
        ]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/storageHealthSnapshot.php', [
            '$disks = pmssStorageHealthDiskInventoryRead();',
        ], [
            "preg_split('/\\r?\\n/', trim((string) \$lsblkOut))" => 'snapshot should not keep a local lsblk parser',
            $symbol.'((string) shell_exec' => 'snapshot should check lsblk exit status before parsing inventory output',
        ]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/storageBenchmark.php', [
            'foreach (pmssStorageHealthDiskInventoryRead() as $meta)',
        ], [
            $symbol.'((string) shell_exec' => 'storageBenchmark.php should check lsblk exit status before parsing inventory output',
        ]);
        $this->pmssAssertRepoFileContainsString('scripts/util/storageBenchmark.php', "require_once __DIR__.'/../lib/storageBenchmark.php';");
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

    public function testSharedCompressionSourceContractsStayTableDriven(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/storageBenchmark.php' => [
                'required' => ['exit(storageBenchmarkMain($argv ?? []));'],
            ],
            'scripts/lib/storageBenchmark.php' => [
                'required' => [
                    'function storageBenchmarkMain(array $argv): int',
                    "'--device-runtime'",
                    "'--require-idle'",
                ],
                'forbidden' => [
                    'function consume'.'CliValue(' => 'storageBenchmark.php should keep one shared CLI dispatcher instead of a standalone consume helper',
                ],
            ],
            'scripts/lib/update.php' => [
                'required' => [
                    "pmssSkeletonBase().'/'.\$file",
                    "'version.php'",
                ],
                'forbidden' => [
                    'function pmssSkeleton'.'Path(' => 'update.php should keep skeleton path joins inline inside updateUserFile()',
                    'function update'.'AptSources(' => 'update.php should keep the canonical pmssUpdateAptSources() symbol only',
                    'function generate'.'Motd(' => 'update.php should not keep a dead MOTD wrapper once callers use Motd::motdGenerate() directly',
                ],
            ],
            'scripts/lib/version.php' => [
                'required' => ['function getPmssVersion('],
            ],
            'scripts/lib/update/users/filesystem.php' => [
                'forbidden' => [
                    'function pmssUserPatch'.'TorrentFrontends(' => 'filesystem.php should not restore removed torrent frontend patch wrappers',
                    "require_once '/scripts/lib/user/torrentPort.php';",
                    "is_readable('/scripts/lib/user/torrentPort.php')",
                    'pmssDelugePortEnsureCurrentUser',
                    'pmssQbittorrentPortEnsureCurrentUser',
                ],
            ],
            'scripts/lib/update/users.php' => [
                'required' => [
                    "require_once __DIR__.'/../user/log.php';",
                    "require_once __DIR__.'/users/filesystem.php';",
                    "require_once __DIR__.'/users/rutorrent.php';",
                    "require_once __DIR__.'/users/context.php';",
                    "require_once __DIR__.'/users/http.php';",
                    "require_once __DIR__.'/users/permissions.php';",
                    "'pmssUserConfigureHttp'",
                    "'pmssUserApplySkeletonFiles'",
                    "'pmssUserUpgradeRutorrent'",
                    "'pmssUserRefreshPermissions'",
                ],
                'forbidden' => [
                    'function pmssUserUpgrade'.'Rutorrent(' => 'users.php should stay thin and delegate ruTorrent maintenance',
                    'function pmssBuildUserContext(' => 'users.php should delegate context building to a domain module',
                    'function pmssUserConfigureHttp(' => 'users.php should delegate HTTP maintenance to a domain module',
                    'function pmssUserRefreshPermissions(' => 'users.php should delegate permission refresh to permissions.php',
                    'pmssEnsureLingerAndDocker($user)' => 'users.php should keep single-user refresh limited to environment handlers',
                    'Missing handler' => 'users.php should not keep a dead missing-handler warning branch once domain modules are required directly',
                ],
            ],
            'scripts/lib/update/users/rutorrent.php' => [
                'required' => [
                    'function pmssUserUpgradeRutorrent(',
                    'function pmssUserMaintainRutorrentPhpCompatibility(',
                    'function pmssUserUpdateThemes(',
                    'retrackers.dat',
                    'Creating ruTorrent RSS settings directory',
                ],
                'forbidden' => [
                    'function pmssUserMaintain'.'Retracker(' => 'rutorrent.php should keep retracker cleanup inside pmssUserEnsurePlugins()',
                ],
            ],
            'scripts/lib/update/osRelease.php' => [
                'required' => ["pmssResolvePathFromEnv('PMSS_OS_RELEASE_PATH', '/etc/os-release')"],
                'forbidden' => [
                    'function pmssOsRelease'.'Path(' => 'osRelease.php should keep the os-release path lookup inline inside cache helpers',
                ],
            ],
            'scripts/lib/update/runtime/profile.php' => [
                'required' => [
                    "if (!is_array(\$GLOBALS['PMSS_PROFILE'] ?? null))",
                    "\$GLOBALS['PMSS_PROFILE'][] = \$entry;",
                ],
                'forbidden' => [
                    'function pmssInit'.'ProfileStore(' => 'runtime/profile.php should keep profile-store initialization inside pmssRecordProfile()',
                ],
            ],
            'scripts/lib/update/userMaintenance.php' => [
                'required' => [
                    'Synchronizing per-user htpasswd',
                    'Checking lighttpd instance',
                    "'description'    => 'updateUser '.\$user",
                    "'stdout_excerpt' => ''",
                    "'stderr_excerpt' => \$stderrExcerpt",
                    'Environment (HTTP/ruTorrent/permissions + linger/systemd/rootless Docker)',
                    "'users/docker.php'",
                    "\$postChecks['Checking lighttpd instance'] = '/scripts/cron/checkLighttpdInstances.php';",
                    "pmssUserLog(\$userTrim, '[WARN] update-step2 user maintenance aborted: '.\$reason);",
                    'pmssLogJson([',
                ],
                'forbidden' => array_merge([
                    'function pmssRunUser'.'PostCheck(' => 'userMaintenance.php should keep optional htpasswd/lighttpd checks inside pmssUpdateAllUsers()',
                    'function pmssBuild'.'UserMaintenanceProfile(' => 'userMaintenance.php should keep per-user profile payload assembly inside pmssUpdateAllUsers()',
                    "function_exists('pmssUpdateUserEnvironment')" => 'userMaintenance.php should not guard helpers that are required at file load time',
                    "function_exists('pmssLogJson')" => 'userMaintenance.php should log its JSON summary directly through the required logging runtime',
                    "function_exists('pmssUserDockerEnabled')" => 'userMaintenance.php should call the required Docker config helper directly',
                ], array_fill_keys([
                    "if (!function_exists('pmssRunAndLog'))",
                    "if (!function_exists('pmssUpdateAllUsers'))",
                    "if (!function_exists('pmssEnsureLingerAndDocker'))",
                    "if (!function_exists('pmssEnsureRootlessDockerInstalled'))",
                    "if (!function_exists('pmssEnsureDockerDependencies'))",
                ], 'userMaintenance.php should not keep dead self-guard wrappers once runtime callers use require_once')),
                'ordered' => [[
                    'needles' => [
                        'pmssUpdateUserEnvironment($userTrim, $rutorrentIndexSha);',
                        'pmssEnsureLingerAndDocker($userTrim);',
                        'foreach ($postChecks as $label => $helperPath)',
                    ],
                    'missingPrefix' => 'Missing user-maintenance phase: ',
                    'orderPrefix' => 'User-maintenance phase order changed at: ',
                ]],
            ],
            'scripts/lib/update/users/docker.php' => [
                'required' => [
                    "require_once dirname(__DIR__, 2).'/user/rootlessDockerConfig.php';",
                    "pmssUserRootlessDockerConfigConverge(\$user, \$home, (int) \$uinfo['uid'], (int) \$uinfo['gid']",
                ],
                'forbidden' => [
                    'function pmssWrite'.'DockerDaemonConfig(' => 'daemon.json convergence should not grow a second local writer',
                    'pmssJsonEncodePretty($payload)' => 'docker.php should use the shared rootless Docker config writer',
                ],
            ],
            'scripts/lib/update/repositories.php' => [
                'required' => [
                    "preg_match('/^[ \\t]*#/', \$line) === 1",
                    'signed-by=',
                ],
                'forbidden' => [
                    'function pmssSonarr'.'SourceLine(' => 'repositories.php should keep Sonarr source detection inside signed-by rewriting',
                ],
            ],
            'scripts/lib/update/users/context.php' => [
                'required' => [
                    'function pmssBuildUserContext(',
                    'www-disabled',
                ],
            ],
            'scripts/lib/update/users/http.php' => [
                'required' => [
                    'function pmssUserConfigureHttp(',
                    'HostHeaderValidation',
                ],
            ],
            'scripts/lib/update/users/permissions.php' => [
                'required' => [
                    'function pmssUserRefreshPermissions(',
                    'PMSS_USER_PERMISSIONS_TIMEOUT',
                    "'-c3'",
                ],
            ],
            'scripts/lib/update/distUpgrade.php' => [
                'required' => ['pmssEnsureBootDefaults('],
            ],
            'scripts/lib/update/distUpgrade/docker.php' => [
                'required' => [
                    'pmssEnsureRootlessDockerInstalled($user);',
                    'pmssEnsureDockerDependencies($user);',
                    "pmssUserLog(\$userTrim, '[SKIP] dist-upgrade: user appears suspended; skipping rootless Docker repair');",
                    "pmssUserLog(\$user, 'dist-upgrade: rootless Docker repair start');",
                ],
                'forbidden' => [
                    "function_exists('pmssEnsureBootDefaults')" => 'distUpgrade.php should call the required boot defaults helper directly',
                    'class_exists(\'users\')' => 'distUpgrade.php should not keep a dead users class guard once userMaintenance.php is required',
                    "function_exists('pmssEnsureRootlessDockerInstalled')" => 'distUpgrade.php should not keep dead rootless helper guards once userMaintenance.php is required',
                    "function_exists('pmssUserLog')" => 'distUpgrade.php should log through the required user logger directly',
                ],
            ],
        ]);
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
            "pmssResourceStoredPayloadWindowMetrics(\$data, 'day')",
            "collectWindowResultsFromData(\$dataLines, ['day' => \$threshold])",
            "pmssRunCliEntrypoint(__FILE__, 'pmssResourceSnapshotRun');",
            'function '.$symbol.'(): int',
        ], ['new ResourceStatsAccumulator([\'day\' => $threshold])']);
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

}
