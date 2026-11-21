<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/cron/checkGui.php';
require_once __DIR__.'/../common/TestCase.php';

class CheckGuiCronTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-check-gui-']; }

    protected function setUp(): void
    {
        parent::setUp();
        // Keep the external permission repair out of these watchdog path fixtures.
        $this->pmssTrackEnvOverrides(['PMSS_DRY_RUN' => '1']);
    }

    public function testRootCronSchedulesCheckGui(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron', '/scripts/cron/checkGui.php', 'root.cron should schedule checkGui.php');
    }

    public function testRootCronAppendsCheckGuiOutputToItsLog(): void
    {
        $cron = $this->pmssReadRepoFile('etc/seedbox/config/root.cron');
        $lines = array_values(array_filter(explode("\n", $cron), static function (string $line): bool {
            return strpos($line, '/scripts/cron/checkGui.php') !== false;
        }));
        $this->assertSame(['*/10 * * * *   root /scripts/cron/checkGui.php >> /var/log/pmss/checkGui.log 2>&1'], $lines);
    }

    public function testStandaloneWatchdogLoadsRequiredSymbols(): void
    {
        $script = <<<'PHP'
$requiredSymbols = ['Logger' => 'class', 'pmssSkeletonBase' => 'function', 'runUserStep' => 'function',
    'pmssUpdateUserRootStageEligible' => 'function', 'pmssCheckGuiRestoreTargetIsDamaged' => 'function',
    // Everything the real (unstubbed) root-stage fallback calls must be loaded by the cron include alone.
    'pmssUpdateUserRootStageFallback' => 'function', 'pmssUpdateUserRootStageDeliver' => 'function',
    'pmssUpdateUserRootStageCounts' => 'function', 'pmssRootStageFileWrite' => 'function',
    'pmssRootUsableDiskMeasure' => 'function', 'pmssCommandCapture' => 'function', 'pmssCommandPath' => 'function',
    'pmssQuotaSnapshotParseRepquotaUserRows' => 'function', 'pmssReadRegularFileContentsVerified' => 'function'];
foreach ($requiredSymbols as $symbol => $type) {
    $loaded = $type === 'class' ? class_exists($symbol, false) : function_exists($symbol);
    if (!$loaded) {
        fwrite(STDERR, 'missing '.$symbol);
        exit(1);
    }
}
echo 'ok';
PHP;

        $output = $this->pmssRunRepoInlinePhpRequire(
            'scripts/cron/checkGui.php',
            $script,
            $this->pmssTestModeEnv(),
            '2>&1',
            true
        );
        $this->assertEquals('ok', trim($output));
    }

    public function testCheckGuiRepairsCoreUserspacePaths(): void
    {
        foreach ([
            'scripts/cron/checkGui.php' => [
                'pmssCheckGuiEnsureUserDirectory($wwwDir',
                "pmssCheckGuiEnsureUserDirectory(\$homeDir.'/data'",
                'pmssCheckGuiRestoreUserFile',
                'pmssCheckGuiWebRootReconcileIfSentinelMissing',
                "'scriptsInc.php'",
                'pmssUserPanelHelperFiles()',
                'pmssCheckGuiManagedUserNameNormalize($thisUser)',
            ],
            'scripts/lib/gui/watchdogPaths.php' => [
                'pmssCheckGuiWebRootSentinelsHealthy', 'pmssUserReconcileWebRoot',
            ],
            'scripts/lib/gui/watchdogRepair.php' => ['pmssCheckGuiRestoreUserIndex'],
        ] as $path => $fragments) {
            $this->pmssAssertRepoFileContainsAllStrings($path, $fragments, 'checkGui should keep core userspace repair wiring: ');
        }
    }

    public function testManagedUserNameNormalizeRejectsUnsafeEntries(): void
    {
        $this->assertSame('dummy', \pmssCheckGuiManagedUserNameNormalize('dummy'));
        foreach (['', 'Dummy', '../dummy', 'Fatal error: boom', array('dummy')] as $entry) {
            $this->assertSame(null, \pmssCheckGuiManagedUserNameNormalize($entry), 'entry '.var_export($entry, true));
        }
    }

    public function testEnsureUserDirectoryCreatesSafeDirectory(): void
    {
        $homeDir = $this->tempDir.'/dummy';
        $this->pmssEnsureDir($homeDir);
        $messages = [];

        $this->assertTrue(\pmssCheckGuiEnsureUserDirectory(
            $homeDir.'/www',
            $this->pmssCurrentOwner(),
            'www',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertTrue(is_dir($homeDir.'/www'));
        $this->assertEquals(0755, fileperms($homeDir.'/www') & 0777);
    }

    public function testEnsureUserDirectoryRejectsTraversalOutsideHome(): void
    {
        $homeDir = $this->tempDir.'/dummy';
        $outsideDir = $this->tempDir.'/outside';
        $this->pmssEnsureDir($homeDir);
        $this->pmssEnsureDir($outsideDir);
        $messages = [];

        $this->assertFalse(\pmssCheckGuiEnsureUserDirectory(
            $homeDir.'/../outside/www',
            'dummy',
            'www',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertFalse(file_exists($outsideDir.'/www'));
        $this->pmssAssertMessagesContain($messages, 'unsafe www directory target');
    }

    public function testWebRootSentinelsRequireNonEmptyRegularFiles(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $this->pmssWriteFiles($homeDir.'/www', ['index.php' => 'panel', 'rutorrent/index.html' => 'rutorrent']);

        $this->assertTrue(\pmssCheckGuiWebRootSentinelsHealthy($homeDir.'/www', $homeDir));
        $this->pmssWriteFile($homeDir.'/www/rutorrent/index.html', '');
        $this->assertFalse(\pmssCheckGuiWebRootSentinelsHealthy($homeDir.'/www', $homeDir));
    }

    public function testMissingSentinelUsesSharedReconciler(): void
    {
        $user = $this->pmssCurrentOwner();
        if (!\pmssValidateUsername($user)) throw new SkipTest('Host account name is outside PMSS account format');
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-check-gui-reconcile-');
        $homeDir = $this->pmssUserHomePath($homeRoot, $user);
        $this->pmssWriteFile($homeDir.'/www/index.php', 'existing panel');

        $skeletonRoot = $this->pmssMakeTempDir('pmss-check-gui-skeleton-');
        $this->pmssWriteFiles($skeletonRoot.'/www', ['index.php' => 'skeleton panel', 'rutorrent/index.html' => 'skeleton rutorrent']);
        $this->pmssTrackEnvOverrides([
            'PMSS_SKEL_DIR' => $skeletonRoot,
            'PMSS_USER_WEB_ROOT_LOCK_DIR' => $this->pmssMakeTempDir('pmss-check-gui-lock-'),
        ]);
        $messages = [];

        $this->assertFalse(\pmssCheckGuiWebRootSentinelsHealthy($homeDir.'/www', $homeDir));
        $this->assertTrue(\pmssCheckGuiWebRootReconcileIfSentinelMissing(
            $user,
            $homeDir,
            $this->pmssMakeArrayLogger($messages)
        ));
        $this->assertEquals('existing panel', file_get_contents($homeDir.'/www/index.php'));
        $this->assertEquals('skeleton rutorrent', file_get_contents($homeDir.'/www/rutorrent/index.html'));
    }

    public function testSingleWatchdogRecoveryRestoresPanelAndRutorrentAfterWebRootDeletion(): void
    {
        $user = $this->pmssCurrentOwner();
        if (!\pmssValidateUsername($user)) throw new SkipTest('Host account name is outside PMSS account format');
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-check-gui-full-recovery-');
        $homeDir = $this->pmssUserHomePath($homeRoot, $user);
        $this->pmssEnsureDir($homeDir.'/www');
        $this->pmssRemoveTree($homeDir.'/www');

        $skeletonRoot = $this->pmssMakeTempDir('pmss-check-gui-full-skeleton-');
        $this->pmssWriteFiles($skeletonRoot.'/www', ['index.php' => 'panel', 'rutorrent/index.html' => 'rutorrent']);
        $this->pmssTrackEnvOverrides([
            'PMSS_SKEL_DIR' => $skeletonRoot,
            'PMSS_USER_WEB_ROOT_LOCK_DIR' => $this->pmssMakeTempDir('pmss-check-gui-full-lock-'),
        ]);
        $messages = [];

        $this->assertFalse(
            \pmssCheckGuiWebRootSentinelsHealthy($homeDir.'/www', $homeDir)
        );
        $this->assertTrue(
            \pmssCheckGuiWebRootReconcileIfSentinelMissing(
                $user,
                $homeDir,
                $this->pmssMakeArrayLogger($messages)
            ),
            implode('|', $messages)
        );
        $this->assertSame('panel', file_get_contents($homeDir.'/www/index.php'));
        $this->assertSame('rutorrent', file_get_contents($homeDir.'/www/rutorrent/index.html'));
        $this->assertTrue(
            \pmssCheckGuiWebRootSentinelsHealthy($homeDir.'/www', $homeDir)
        );
    }

    public function testWatchdogRecoveryIsIdempotentAfterTheFirstRun(): void
    {
        $user = $this->pmssCurrentOwner();
        if (!\pmssValidateUsername($user)) throw new SkipTest('Host account name is outside PMSS account format');
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-check-gui-idempotent-');
        $homeDir = $this->pmssUserHomePath($homeRoot, $user);
        $this->pmssEnsureDir($homeDir);
        $skeletonRoot = $this->pmssMakeTempDir('pmss-check-gui-idempotent-skeleton-');
        $this->pmssWriteFiles($skeletonRoot.'/www', ['index.php' => 'panel', 'rutorrent/index.html' => 'rutorrent']);
        $this->pmssTrackEnvOverrides([
            'PMSS_SKEL_DIR' => $skeletonRoot,
            'PMSS_USER_WEB_ROOT_LOCK_DIR' => $this->pmssMakeTempDir('pmss-check-gui-idempotent-lock-'),
        ]);
        $messages = [];

        $this->assertTrue(\pmssCheckGuiWebRootReconcileIfSentinelMissing(
            $user,
            $homeDir,
            $this->pmssMakeArrayLogger($messages)
        ));
        $before = $this->pmssTreeSnapshot($homeDir);
        $messages = [];
        $this->assertTrue(\pmssCheckGuiWebRootReconcileIfSentinelMissing(
            $user,
            $homeDir,
            $this->pmssMakeArrayLogger($messages)
        ));

        $this->assertSame($before, $this->pmssTreeSnapshot($homeDir));
        $this->assertFalse($this->pmssMessagesContain($messages, 'Restored complete web root'));
    }

    public function testUnsafeWebRootSymlinkIsRefusedWithoutTouchingItsTarget(): void
    {
        $user = 'dummy';
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-check-gui-symlink-');
        $homeDir = $this->pmssUserHomePath($homeRoot, $user);
        $outsideDir = $this->pmssMakeTempDir('pmss-check-gui-outside-');
        $this->pmssWriteFile($outsideDir.'/sentinel.txt', 'outside');
        $this->pmssCreateSymlinkedDirectoryOrSkip($outsideDir, $homeDir.'/www');
        $skeletonRoot = $this->pmssMakeTempDir('pmss-check-gui-symlink-skeleton-');
        $this->pmssWriteFiles($skeletonRoot.'/www', ['index.php' => 'panel', 'rutorrent/index.html' => 'rutorrent']);
        $this->pmssTrackEnvOverrides([
            'PMSS_SKEL_DIR' => $skeletonRoot,
            'PMSS_USER_WEB_ROOT_LOCK_DIR' => $this->pmssMakeTempDir('pmss-check-gui-symlink-lock-'),
        ]);
        $messages = [];

        $this->assertFalse(\pmssCheckGuiWebRootReconcileIfSentinelMissing(
            $user,
            $homeDir,
            $this->pmssMakeArrayLogger($messages)
        ));
        $this->assertSame('outside', file_get_contents($outsideDir.'/sentinel.txt'));
        $this->assertSame($outsideDir, readlink($homeDir.'/www'));
    }

    public function testWrongWebRootPathTypeIsRefusedWithoutReplacingIt(): void
    {
        $user = 'dummy';
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-check-gui-path-type-');
        $homeDir = $this->pmssUserHomePath($homeRoot, $user);
        $this->pmssWriteFile($homeDir.'/www', 'not-a-directory');
        $skeletonRoot = $this->pmssMakeTempDir('pmss-check-gui-path-type-skeleton-');
        $this->pmssWriteFiles($skeletonRoot.'/www', ['index.php' => 'panel', 'rutorrent/index.html' => 'rutorrent']);
        $this->pmssTrackEnvOverrides([
            'PMSS_SKEL_DIR' => $skeletonRoot,
            'PMSS_USER_WEB_ROOT_LOCK_DIR' => $this->pmssMakeTempDir('pmss-check-gui-path-type-lock-'),
        ]);
        $messages = [];

        $this->assertFalse(\pmssCheckGuiWebRootReconcileIfSentinelMissing(
            $user,
            $homeDir,
            $this->pmssMakeArrayLogger($messages)
        ));
        $this->assertSame('not-a-directory', file_get_contents($homeDir.'/www'));
    }

    public function testHealthySentinelsDoNotInvokeReconciler(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $this->pmssWriteFiles($homeDir.'/www', ['index.php' => 'panel', 'rutorrent/index.html' => 'rutorrent']);
        $messages = [];

        $this->assertTrue(\pmssCheckGuiWebRootReconcileIfSentinelMissing(
            'dummy',
            $homeDir,
            $this->pmssMakeArrayLogger($messages)
        ));
        $this->assertFalse($this->pmssMessagesContain($messages, 'Restored complete web root'));
    }

    public function testEnsureUserDirectoryRejectsSymlinkedTarget(): void
    {
        $homeDir = $this->tempDir.'/dummy';
        $outsideDir = $this->tempDir.'/outside';
        $this->pmssEnsureDir($homeDir);
        $this->pmssEnsureDir($outsideDir);
        chmod($outsideDir, 0700);
        $before = lstat($outsideDir);
        symlink($outsideDir, $homeDir.'/www');
        $messages = [];

        $this->assertFalse(\pmssCheckGuiEnsureUserDirectory(
            $homeDir.'/www',
            'dummy',
            'www',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertTrue(is_link($homeDir.'/www'));
        $this->pmssAssertPathOwnerAndModeSame($before, $outsideDir);
        $this->pmssAssertMessagesContain($messages, 'unsafe www directory target');
    }

    public function testApplyOwnershipRefusesSymlinkedFinalNode(): void
    {
        $target = $this->pmssWriteFile($this->tempDir.'/outside.txt', 'outside');
        $link = $this->tempDir.'/linked.txt';
        symlink($target, $link);
        $before = lstat($target);
        $messages = [];

        $this->assertFalse(\pmssCheckGuiApplyOwnership(
            $link,
            $this->pmssCurrentOwner(),
            $this->pmssMakeArrayLogger($messages)
        ));
        $this->pmssAssertPathOwnerAndModeSame($before, $target);
        $this->pmssAssertMessagesContain($messages, 'refusing to apply ownership to symlinked');
    }

    public function testApplyOwnershipAcceptsRealFileAndDirectory(): void
    {
        $file = $this->pmssWriteFile($this->tempDir.'/panel.php', 'panel');
        $directory = $this->pmssEnsureDir($this->tempDir.'/www');
        $user = $this->pmssCurrentOwner();
        $messages = [];

        foreach ([$file, $directory] as $path) {
            $this->assertTrue(\pmssCheckGuiApplyOwnership(
                $path,
                $user,
                $this->pmssMakeArrayLogger($messages)
            ));
            clearstatcache(true, $path);
            $this->assertSame(posix_geteuid(), fileowner($path));
        }
    }

    public function testRestoreUserIndexCopiesSafeSkeletonSource(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $sourceFile = $this->tempDir.'/skel/index.php';
        $this->pmssWriteFile($sourceFile, "<?php echo 'ok';\n");
        $messages = [];

        $this->assertTrue(\pmssCheckGuiRestoreUserIndex(
            $homeDir.'/www/index.php',
            $sourceFile,
            $this->pmssCurrentOwner(),
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertEquals("<?php echo 'ok';\n", file_get_contents($homeDir.'/www/index.php'));
        $this->assertSame(posix_geteuid(), fileowner($homeDir.'/www/index.php'));
        $this->assertSame(0644, fileperms($homeDir.'/www/index.php') & 0777);
    }

    public function testRestoreUserFileRepairsNonZeroTruncation(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $sourceFile = $this->tempDir.'/skel/scriptsInc.php';
        $targetFile = $homeDir.'/www/scriptsInc.php';
        $sourceContent = "<?php\nfunction panelHelper(): string { return 'ok'; }\n";
        $this->pmssWriteFile($sourceFile, $sourceContent);
        $this->pmssWriteFile($targetFile, substr($sourceContent, 0, -12));
        $messages = [];

        $this->assertTrue(\pmssCheckGuiRestoreUserFile(
            $targetFile,
            $sourceFile,
            $this->pmssCurrentOwner(),
            'scriptsInc.php',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertEquals($sourceContent, file_get_contents($targetFile));
    }

    public function testRestoreUserFileRepairsDamagedPart(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $sourceFile = $this->tempDir.'/skel/scriptsIncFormat.php';
        $targetFile = $homeDir.'/www/scriptsIncFormat.php';
        $sourceContent = $this->pmssReadRepoFile('etc/skel/www/scriptsIncFormat.php');
        $this->pmssWriteFile($sourceFile, $sourceContent);
        $this->pmssWriteFile($targetFile, substr($sourceContent, 0, 50));
        $messages = array();

        $this->assertTrue(\pmssCheckGuiRestoreUserFile(
            $targetFile, $sourceFile, $this->pmssCurrentOwner(), 'scriptsIncFormat.php',
            $this->pmssMakeArrayLogger($messages), $homeDir
        ));
        $this->assertSame($sourceContent, file_get_contents($targetFile));
    }

    public function testRootStageRestoresPanelPartTruncatedAtBlockBoundary(): void
    {
        $content = $this->pmssReadRepoFile('etc/skel/www/scriptsIncAppProcess.php');
        $this->assertTrue(strlen($content) > 4096);
        // This account has no ownership to confirm after the simulated publication.
        $fixture = $this->pmssRootStageRestoreFixture($content, substr($content, 0, 4096), [], 'missing-panel-account');
        $this->assertTrue($fixture['restored']);
        $this->assertSame(['accountWrite', 'eligible', 'deliver'], $fixture['events']);
        $this->assertSame([[$fixture['user'], $fixture['targetFile'], $content]], $fixture['deliveries']);
        $this->pmssAssertMessagesContain($fixture['messages'], 'through the root stage (over quota)');
    }

    public function testRootStageRestoresPrefixThatParsesAsInlineHtml(): void
    {
        $prefix = "<?php echo 'panel'; ?>\n<div>partial";
        token_get_all($prefix, TOKEN_PARSE);
        $fixture = $this->pmssRootStageRestoreFixture($prefix.'</div>', $prefix);
        $this->assertTrue(\pmssCheckGuiRestoreTargetIsDamaged($fixture['targetFile'], $prefix.'</div>'));
        $this->assertTrue($fixture['restored']);
        $this->assertSame(1, count($fixture['deliveries']));
    }

    public function testRootStageRestoresRealIndexPrefixThatStillParses(): void
    {
        $content = $this->pmssReadRepoFile('etc/skel/www/index.php');
        $this->assertTrue(strlen($content) > 32768);
        $prefix = substr($content, 0, 32768);
        token_get_all($prefix, TOKEN_PARSE);
        $fixture = $this->pmssRootStageRestoreFixture($content, $prefix);
        $this->assertTrue(\pmssCheckGuiRestoreTargetIsDamaged($fixture['targetFile'], $content));
        $this->assertTrue($fixture['restored']);
        $this->assertSame([[$fixture['user'], $fixture['targetFile'], $content]], $fixture['deliveries']);
    }

    public function testRootStageRestoresMissingAndEmptyPanelFiles(): void
    {
        foreach ([null, ''] as $bytes) {
            $fixture = $this->pmssRootStageRestoreFixture("<?php echo 'panel';\n", $bytes);
            $this->assertTrue($fixture['restored']);
            $this->assertSame(['accountWrite', 'eligible', 'deliver'], $fixture['events']);
            $this->assertSame(1, count($fixture['deliveries']));
        }
    }

    public function testRootStageLeavesCompleteNewerPanelFileAlone(): void
    {
        $content = "<?php echo 'skeleton panel';\n";
        $newer = "<?php echo 'newer panel';\n";
        $fixture = $this->pmssRootStageRestoreFixture($content, $newer);
        $this->assertFalse($fixture['restored']);
        $this->assertSame(['accountWrite'], $fixture['events']);
        $this->assertSame([], $fixture['deliveries']);
        $this->assertSame($newer, file_get_contents($fixture['targetFile']));
        $this->pmssAssertMessagesContain($fixture['messages'], 'unable to atomically replace');
    }

    public function testRootStageDoesNotExamineLargerUnparseableCopy(): void
    {
        $content = "<?php echo 'panel';\n";
        $larger = "<?php function panel() { echo 'incomplete";
        $fixture = $this->pmssRootStageRestoreFixture($content, $larger);
        $this->assertFalse(\pmssCheckGuiRestoreTargetIsDamaged($fixture['targetFile'], $content));
        $this->assertFalse($fixture['restored']);
        $this->assertSame([], $fixture['deliveries']);
        $this->assertSame($larger, file_get_contents($fixture['targetFile']));
    }

    public function testRootStageRestoresUnparseableNonPrefixCopy(): void
    {
        $fixture = $this->pmssRootStageRestoreFixture("<?php echo 'complete panel';\n", '<?php broken(');
        $this->assertTrue($fixture['restored']);
        $this->assertSame(1, count($fixture['deliveries']));
    }

    public function testRootStageRefusesSymlinkTargetBeforeAccountWrite(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $source = $this->pmssWriteFile($this->tempDir.'/skeleton.php', "<?php echo 'panel';\n");
        $outside = $this->pmssWriteFile($this->tempDir.'/outside.php', '');
        $target = $homeDir.'/www/index.php';
        symlink($outside, $target);
        $messages = []; $events = [];
        $this->assertFalse(\pmssCheckGuiRestoreTargetIsDamaged($target, file_get_contents($source)));
        $this->assertFalse(\pmssCheckGuiRestoreUserFile($target, $source, $this->pmssCurrentOwner(), 'index.php',
            $this->pmssMakeArrayLogger($messages), $homeDir,
            $this->pmssMakeCallbacks(['accountWrite' => false, 'eligible' => 'over quota', 'deliver' => true], $events)));
        $this->assertSame([], $events);
        $this->assertSame('', file_get_contents($outside));
        $this->pmssAssertMessagesContain($messages, 'unsafe index.php target');
    }

    public function testRootStageFailedDeliveryKeepsOldBytesWithoutRetry(): void
    {
        $old = '<?php broken(';
        $fixture = $this->pmssRootStageRestoreFixture("<?php echo 'complete panel';\n", $old, [
            'deliver' => static function ($user, $target, $content, &$failure): bool {
                $failure = 'stage=publish rc=23';
                return false;
            },
        ]);
        $this->assertFalse($fixture['restored']);
        $this->assertSame(['accountWrite', 'eligible', 'deliver'], $fixture['events']);
        $this->assertSame($old, file_get_contents($fixture['targetFile']));
        $this->pmssAssertMessagesContain($fixture['messages'], 'failed: stage=publish rc=23');
        $this->pmssAssertMessagesContain($fixture['messages'], 'unable to atomically replace');
    }

    public function testRootStageSkipsLowReserveAndIneligibleAccounts(): void
    {
        foreach (['root reserve low', null] as $reason) {
            $fixture = $this->pmssRootStageRestoreFixture("<?php echo 'panel';\n", '',
                $this->pmssMakeCallbacks(['eligible' => $reason]));
            $this->assertFalse($fixture['restored']);
            $this->assertSame([], $fixture['deliveries']);
            if ($reason !== null) $this->pmssAssertMessagesContain($fixture['messages'], 'skipped: root reserve low');
            $this->pmssAssertMessagesContain($fixture['messages'], 'unable to atomically replace');
        }
    }

    public function testSuccessfulAccountWriteDoesNotReachRootStageEligibility(): void
    {
        $fixture = $this->pmssRootStageRestoreFixture("<?php echo 'panel';\n", '<?php broken(', [
            'accountWrite' => static function (): bool { return true; },
            'eligible' => static function () { throw new \RuntimeException('Unexpected eligibility check'); },
        ]);
        $this->assertTrue($fixture['restored']);
        $this->assertSame([], $fixture['deliveries']);
    }

    /** Simulate writer outcomes; never run quota observations or root publication. */
    private function pmssRootStageRestoreFixture(string $content, ?string $old, array $ops = [], string $user = ''): array
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->pmssMakeTempDir('pmss-check-gui-stage-'), 'dummy');
        $sourceFile = $this->pmssWriteFile($homeDir.'/skeleton.php', $content);
        $targetFile = $homeDir.'/www/scriptsInc.php';
        if ($old !== null) $this->pmssWriteFile($targetFile, $old);
        $user = $user === '' ? $this->pmssCurrentOwner() : $user;
        $messages = []; $events = []; $deliveries = [];
        $ops += $this->pmssMakeCallbacks(['accountWrite' => false, 'eligible' => 'over quota'], $events);
        $deliver = $ops['deliver'] ?? static function (): bool { return true; };
        $ops['deliver'] = static function ($user, $target, $bytes, &$failure) use ($deliver, &$events, &$deliveries): bool {
            $events[] = 'deliver';
            $deliveries[] = [$user, $target, $bytes];
            return $deliver($user, $target, $bytes, $failure);
        };
        $restored = \pmssCheckGuiRestoreUserFile($targetFile, $sourceFile, $user, 'scriptsInc.php',
            $this->pmssMakeArrayLogger($messages), $homeDir, $ops);
        \pmssUpdateUserRootStageCounts($user);
        return compact('restored', 'targetFile', 'user', 'messages', 'events', 'deliveries');
    }

    public function testRestoreMissingCatalogMakesDeliveredAppProcessUsable(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'fixtur01');
        $catalog = 'toggleAppCatalog.php';
        $sourceContent = $this->pmssReadRepoFile('etc/skel/www/'.$catalog);
        $sourceFile = $this->pmssWriteFile($this->tempDir.'/skel/'.$catalog, $sourceContent);
        $part = $this->pmssWriteFile($homeDir.'/www/scriptsIncAppProcess.php',
            $this->pmssReadRepoFile('etc/skel/www/scriptsIncAppProcess.php'));
        $messages = array();

        // Reproduce the mixed delivery: the new app part exists but its catalog does not.
        $this->assertFalse(file_exists($homeDir.'/www/'.$catalog));
        $this->assertTrue(\pmssCheckGuiRestoreUserFile(
            $homeDir.'/www/'.$catalog, $sourceFile, $this->pmssCurrentOwner(), $catalog,
            $this->pmssMakeArrayLogger($messages), $homeDir
        ));
        $this->assertSame($sourceContent, file_get_contents($homeDir.'/www/'.$catalog));
        $result = $this->pmssExecInlinePhp('require '.var_export($part, true).';'
            .'echo is_array(pmssCustomerManagedAppDefinitions()) ? "ok" : "invalid";');
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame('ok', $result['output']);
    }

    public function testRestoreUserFileReplacesLargerDivergentPanelFile(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $sourceFile = $this->tempDir.'/skel/scriptsInc.php';
        $targetFile = $homeDir.'/www/scriptsInc.php';
        $sourceContent = "<?php echo 'source';\n";
        $targetContent = "<?php function oldPanelHelper() { echo 'incomplete";
        $this->pmssWriteFile($sourceFile, $sourceContent);
        $this->pmssWriteFile($targetFile, $targetContent);
        $messages = [];

        $this->assertTrue(\pmssCheckGuiRestoreUserFile(
            $targetFile,
            $sourceFile,
            $this->pmssCurrentOwner(),
            'scriptsInc.php',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertSame($sourceContent, file_get_contents($targetFile));
        $this->pmssAssertMessagesContain($messages, 'Restoring scriptsInc.php');
    }

    public function testRestoreUserFileReplacesSameSizeCorruption(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $sourceFile = $this->tempDir.'/skel/scriptsInc.php';
        $targetFile = $homeDir.'/www/scriptsInc.php';
        $sourceContent = "<?php echo 'source';\n";
        $this->pmssWriteFile($sourceFile, $sourceContent);
        $this->pmssWriteFile($targetFile, str_replace('source', 'broken', $sourceContent));
        $messages = [];

        $this->assertTrue(\pmssCheckGuiRestoreUserFile(
            $targetFile, $sourceFile, $this->pmssCurrentOwner(), 'scriptsInc.php',
            $this->pmssMakeArrayLogger($messages), $homeDir
        ));
        $this->assertSame($sourceContent, file_get_contents($targetFile));
    }

    public function testRestoreUserFileKeepsMatchingPanelFile(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $sourceFile = $this->tempDir.'/skel/scriptsInc.php';
        $targetFile = $homeDir.'/www/scriptsInc.php';
        $sourceContent = "<?php echo 'source';\n";
        $this->pmssWriteFile($sourceFile, $sourceContent);
        $this->pmssWriteFile($targetFile, $sourceContent);
        $messages = [];

        $this->assertTrue(\pmssCheckGuiRestoreUserFile(
            $targetFile, $sourceFile, $this->pmssCurrentOwner(), 'scriptsInc.php',
            $this->pmssMakeArrayLogger($messages), $homeDir
        ));
        $this->assertFalse($this->pmssMessagesContain($messages, 'Restoring scriptsInc.php'));
    }

    public function testRestoreUserFileKeepsExistingFileWithoutSkeleton(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $targetFile = $homeDir.'/www/scriptsInc.php';
        $content = "<?php echo 'existing';\n";
        $this->pmssWriteFile($targetFile, $content);
        $messages = [];

        $this->assertTrue(\pmssCheckGuiRestoreUserFile(
            $targetFile, $this->tempDir.'/skel/missing.php', $this->pmssCurrentOwner(),
            'scriptsInc.php', $this->pmssMakeArrayLogger($messages), $homeDir
        ));
        $this->assertSame($content, file_get_contents($targetFile));
    }

    public function testRestoreUserIndexRejectsSymlinkTarget(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $sourceFile = $this->tempDir.'/skel/index.php';
        $outsideFile = $this->tempDir.'/outside.php';
        $this->pmssWriteFile($sourceFile, "<?php echo 'ok';\n");
        $this->pmssWriteFile($outsideFile, "outside\n");
        symlink($outsideFile, $homeDir.'/www/index.php');
        $messages = [];

        $this->assertFalse(\pmssCheckGuiRestoreUserIndex(
            $homeDir.'/www/index.php',
            $sourceFile,
            'dummy',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertEquals("outside\n", file_get_contents($outsideFile));
        $this->pmssAssertMessagesContain($messages, 'unsafe index.php target');
    }

    public function testRestoreUserIndexRejectsSymlinkSource(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $realSource = $this->tempDir.'/skel-real/index.php';
        $sourceFile = $this->tempDir.'/skel/index.php';
        $this->pmssWriteFile($realSource, "<?php echo 'ok';\n");
        $this->pmssEnsureDir(dirname($sourceFile));
        symlink($realSource, $sourceFile);
        $messages = [];

        $this->assertFalse(\pmssCheckGuiRestoreUserIndex(
            $homeDir.'/www/index.php',
            $sourceFile,
            'dummy',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertFalse(file_exists($homeDir.'/www/index.php'));
        $this->pmssAssertMessagesContain($messages, 'missing skeleton source');
    }

    private function pmssTreeSnapshot(string $path, string $relative = ''): array
    {
        if (is_link($path)) {
            return [$relative => ['type' => 'link', 'target' => (string) readlink($path)]];
        }
        if (is_file($path)) {
            return [$relative => ['type' => 'file', 'content' => (string) file_get_contents($path)]];
        }
        if (!is_dir($path)) {
            return [$relative => ['type' => 'missing']];
        }

        $snapshot = [$relative => ['type' => 'dir']];
        foreach (scandir($path) ?: [] as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $childRelative = $relative === '' ? $child : $relative.'/'.$child;
            $snapshot += $this->pmssTreeSnapshot($path.'/'.$child, $childRelative);
        }
        ksort($snapshot);
        return $snapshot;
    }
}
