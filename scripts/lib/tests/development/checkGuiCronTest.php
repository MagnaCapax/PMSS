<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/cron/checkGui.php';
require_once __DIR__.'/../common/TestCase.php';

class CheckGuiCronTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-check-gui-');
    }

    public function testRootCronSchedulesCheckGui(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron', '/scripts/cron/checkGui.php', 'root.cron should schedule checkGui.php');
    }

    public function testCheckGuiRepairsCoreUserspacePaths(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/checkGui.php',
            [
                'pmssCheckGuiEnsureUserDirectory($wwwDir',
                'pmssCheckGuiEnsureUserDirectory($dataDir',
                'pmssCheckGuiRestoreUserIndex',
                'pmssCheckGuiRestoreUserFile',
                'pmssCheckGuiWebRootSentinelsHealthy',
                'pmssCheckGuiWebRootReconcileIfSentinelMissing',
                'pmssUserReconcileWebRoot',
                "'scriptsInc.php'",
                'pmssCheckGuiManagedUserNameNormalize($thisUser)',
            ],
            'checkGui should keep core userspace repair wiring: '
        );
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
        $this->pmssWriteFile($homeDir.'/www/index.php', 'panel');
        $this->pmssWriteFile($homeDir.'/www/rutorrent/index.html', 'rutorrent');

        $this->assertTrue(\pmssCheckGuiWebRootSentinelsHealthy($homeDir.'/www', $homeDir));
        $this->pmssWriteFile($homeDir.'/www/rutorrent/index.html', '');
        $this->assertFalse(\pmssCheckGuiWebRootSentinelsHealthy($homeDir.'/www', $homeDir));
    }

    public function testMissingSentinelUsesSharedReconciler(): void
    {
        $user = 'dummy';
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-check-gui-reconcile-');
        $homeDir = $this->pmssUserHomePath($homeRoot, $user);
        $this->pmssEnsureDir($homeDir.'/www');
        $this->pmssWriteFile($homeDir.'/www/index.php', 'existing panel');

        $skeletonRoot = $this->pmssMakeTempDir('pmss-check-gui-skeleton-');
        $this->pmssWriteFile($skeletonRoot.'/www/index.php', 'skeleton panel');
        $this->pmssWriteFile($skeletonRoot.'/www/rutorrent/index.html', 'skeleton rutorrent');
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
        $user = 'dummy';
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-check-gui-full-recovery-');
        $homeDir = $this->pmssUserHomePath($homeRoot, $user);
        $this->pmssEnsureDir($homeDir);
        $this->pmssEnsureDir($homeDir.'/www');
        $this->pmssRemoveTree($homeDir.'/www');

        $skeletonRoot = $this->pmssMakeTempDir('pmss-check-gui-full-skeleton-');
        $this->pmssWriteFile($skeletonRoot.'/www/index.php', 'panel');
        $this->pmssWriteFile($skeletonRoot.'/www/rutorrent/index.html', 'rutorrent');
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
        $user = 'dummy';
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-check-gui-idempotent-');
        $homeDir = $this->pmssUserHomePath($homeRoot, $user);
        $this->pmssEnsureDir($homeDir);
        $skeletonRoot = $this->pmssMakeTempDir('pmss-check-gui-idempotent-skeleton-');
        $this->pmssWriteFile($skeletonRoot.'/www/index.php', 'panel');
        $this->pmssWriteFile($skeletonRoot.'/www/rutorrent/index.html', 'rutorrent');
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
        $this->pmssEnsureDir($homeDir);
        $this->pmssCreateSymlinkOrSkip($outsideDir, $homeDir.'/www');
        $skeletonRoot = $this->pmssMakeTempDir('pmss-check-gui-symlink-skeleton-');
        $this->pmssWriteFile($skeletonRoot.'/www/index.php', 'panel');
        $this->pmssWriteFile($skeletonRoot.'/www/rutorrent/index.html', 'rutorrent');
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
        $this->pmssEnsureDir($homeDir);
        $this->pmssWriteFile($homeDir.'/www', 'not-a-directory');
        $skeletonRoot = $this->pmssMakeTempDir('pmss-check-gui-path-type-skeleton-');
        $this->pmssWriteFile($skeletonRoot.'/www/index.php', 'panel');
        $this->pmssWriteFile($skeletonRoot.'/www/rutorrent/index.html', 'rutorrent');
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
        $this->pmssWriteFile($homeDir.'/www/index.php', 'panel');
        $this->pmssWriteFile($homeDir.'/www/rutorrent/index.html', 'rutorrent');
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
        $this->pmssAssertMessagesContain($messages, 'unsafe www directory target');
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

    public function testRestoreUserFileKeepsLargerExistingPanelFile(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $sourceFile = $this->tempDir.'/skel/scriptsInc.php';
        $targetFile = $homeDir.'/www/scriptsInc.php';
        $sourceContent = "<?php echo 'source';\n";
        $targetContent = $sourceContent."// local trailing content\n";
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
        $this->assertEquals($targetContent, file_get_contents($targetFile));
        $this->assertFalse($this->pmssMessagesContain($messages, 'Restoring scriptsInc.php'));
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
