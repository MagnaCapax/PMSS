<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users.php';

/** Hermetic coverage for the shared web-root recovery contract. */
class UserWebRootReconcileTest extends TestCase
{
    private $homeRoot;
    private $home;
    private $skeleton;
    private $config;
    private $user = 'reconcileuser';
    protected function setUp(): void
    {
        $this->homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-web-reconcile-');
        $this->home = $this->pmssUserHomePath($this->homeRoot, $this->user);
        $this->pmssEnsureDir($this->home.'/data');
        $this->skeleton = $this->pmssMakeTempDir('pmss-web-reconcile-skel-').'/www';
        $this->config = $this->pmssMakeTempDir('pmss-web-reconcile-config-');
        $this->pmssSeedSkeleton();
        $this->pmssSeedRutorrentTemplates();
        $this->pmssTrackEnvOverrides([
            'PMSS_SKEL_DIR' => dirname($this->skeleton),
            'PMSS_CONFIG_DIR' => $this->config,
            'PMSS_USER_WEB_ROOT_LOCK_DIR' => $this->pmssMakeTempDir('pmss-web-reconcile-lock-'),
        ]);
    }
    public function testFullRestoreInstallsSkeletonLinksAndGeneratedConfig(): void
    {
        $this->pmssEnsureDir($this->home.'/www');
        $this->pmssWriteFile($this->home.'/.local/share/pmss/rutorrent/share/state.ini', 'durable');
        $messages = [];
        $this->assertTrue(pmssUserReconcileWebRoot($this->context(), $this->logger($messages)), implode('|', $messages));
        $this->assertTrue(is_dir($this->home.'/www'));
        $this->assertEquals('panel', file_get_contents($this->home.'/www/index.php'));
        $this->assertEquals('rutorrent', file_get_contents($this->home.'/www/rutorrent/index.html'));
        $this->assertTrue(is_link($this->home.'/www/data'), 'data link missing');
        $this->assertSame('../data', readlink($this->home.'/www/data'));
        $this->assertSame('../../.local/share/pmss/rutorrent/share', readlink($this->home.'/www/rutorrent/share'));
        $this->assertStringContainsString('unix://'.$this->home.'/.rtorrent.socket', file_get_contents($this->home.'/www/rutorrent/conf/config.php'));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Restored complete web root'), implode('|', $messages));
        $this->assertMatches(
            '/web_root_reconcile reason=missing-or-empty-web-root mode=full-restore files_restored=[1-9][0-9]* duration_ms=[0-9]+ preserved_conflict=0/',
            $this->pmssReconcileSummary($messages)
        );
    }
    public function testFullRestorePreservesCustomerStateAfterWebRootDeletion(): void
    {
        $this->pmssEnsureDir($this->home.'/www');
        $this->pmssWriteFile($this->home.'/data/keep.bin', 'data-state');
        $this->pmssWriteFile($this->home.'/watch/keep.torrent', 'watch-state');
        $this->pmssWriteFile($this->home.'/session/keep.state', 'session-state');
        $this->pmssWriteFile($this->home.'/.rtorrent.rc.custom', 'rtorrent-state');
        $this->pmssWriteFile($this->home.'/.lighttpd/custom', 'lighttpd-state');
        $this->pmssWriteFile($this->home.'/.lighttpd/custom.d/override.conf', 'override-state');
        $this->pmssWriteFile($this->home.'/www/public/index.html', 'public-state');
        $this->pmssWriteFile($this->home.'/www/rutorrent/share/users/profile.ini', 'user-override');

        $migrationMessages = [];
        pmssUserMigrateWebRootState($this->context(), $this->logger($migrationMessages));
        $before = [
            'data' => file_get_contents($this->home.'/data/keep.bin'),
            'watch' => file_get_contents($this->home.'/watch/keep.torrent'),
            'session' => file_get_contents($this->home.'/session/keep.state'),
            'public' => file_get_contents($this->home.'/www/public/index.html'),
            'share' => file_get_contents($this->home.'/www/rutorrent/share/users/profile.ini'),
            'rtorrent' => file_get_contents($this->home.'/.rtorrent.rc.custom'),
            'lighttpd' => file_get_contents($this->home.'/.lighttpd/custom'),
            'lighttpd_override' => file_get_contents($this->home.'/.lighttpd/custom.d/override.conf'),
        ];

        $this->pmssRemoveTree($this->home.'/www');
        $messages = [];
        $this->assertTrue(pmssUserReconcileWebRoot($this->context(), $this->logger($messages)));

        $after = [
            'data' => file_get_contents($this->home.'/data/keep.bin'),
            'watch' => file_get_contents($this->home.'/watch/keep.torrent'),
            'session' => file_get_contents($this->home.'/session/keep.state'),
            'public' => file_get_contents($this->home.'/www/public/index.html'),
            'share' => file_get_contents($this->home.'/www/rutorrent/share/users/profile.ini'),
            'rtorrent' => file_get_contents($this->home.'/.rtorrent.rc.custom'),
            'lighttpd' => file_get_contents($this->home.'/.lighttpd/custom'),
            'lighttpd_override' => file_get_contents($this->home.'/.lighttpd/custom.d/override.conf'),
        ];
        $this->assertSame($before, $after);
        $this->assertTrue(is_link($this->home.'/www/public'));
        $this->assertTrue(is_link($this->home.'/www/rutorrent/share'));
    }
    public function testHealthyRootIsIdempotent(): void
    {
        $messages = [];
        $this->assertTrue(pmssUserReconcileWebRoot($this->context(), $this->logger($messages)), implode('|', $messages));
        $before = $this->pmssTreeSnapshot($this->home);
        $messages = [];
        $this->assertTrue(pmssUserReconcileWebRoot($this->context(), $this->logger($messages)));
        $after = $this->pmssTreeSnapshot($this->home);
        $this->assertSame($before, $after);
        $this->assertFalse($this->pmssMessagesContain($messages, 'Restored complete web root'));
        $this->assertMatches(
            '/web_root_reconcile reason=managed-entry-check mode=partial-merge files_restored=0 duration_ms=[0-9]+ preserved_conflict=[0-9]+/',
            $this->pmssReconcileSummary($messages)
        );
    }
    public function testPartialMergeAddsMissingEntriesWithoutOverwritingCustomerFiles(): void
    {
        $this->pmssEnsureDir($this->home.'/www');
        $this->pmssWriteFile($this->home.'/www/index.php', 'customer-panel');
        $this->pmssWriteFile($this->home.'/www/customer.txt', 'customer-data');
        $this->pmssWriteFile($this->home.'/www/managed-dir', 'customer-path');
        $this->pmssWriteFile($this->skeleton.'/managed-dir/child.php', 'managed-child');
        $outside = $this->pmssMakeTempDir('pmss-web-reconcile-outside-');
        $outsideContent = $outside.'/secret.txt';
        $this->pmssWriteFile($outsideContent, 'must-not-change');
        $this->pmssCreateSymlinkOrSkip($outsideContent, $this->skeleton.'/unsafe.php');
        $messages = [];
        $this->assertTrue(pmssUserReconcileWebRoot($this->context(), $this->logger($messages)));
        $this->assertEquals('customer-panel', file_get_contents($this->home.'/www/index.php'));
        $this->assertEquals('customer-data', file_get_contents($this->home.'/www/customer.txt'));
        $this->assertEquals('customer-path', file_get_contents($this->home.'/www/managed-dir'));
        $this->assertEquals('rutorrent', file_get_contents($this->home.'/www/rutorrent/index.html'));
        $this->assertEquals('must-not-change', file_get_contents($outsideContent));
        $this->assertFalse(is_link($this->home.'/www/unsafe.php'));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Refusing unsafe skeleton symlink: unsafe.php'));
        $this->assertFalse($this->pmssMessagesContain($messages, 'Restored complete web root'));
        $this->assertMatches(
            '/web_root_reconcile reason=managed-entry-check mode=partial-merge files_restored=[1-9][0-9]* duration_ms=[0-9]+ preserved_conflict=[1-9][0-9]*/',
            $this->pmssReconcileSummary($messages)
        );
    }
    public function testPerUserLockSkipsWithoutChangingRoot(): void
    {
        $lockDir = getenv('PMSS_USER_WEB_ROOT_LOCK_DIR');
        $this->assertTrue(is_string($lockDir) && $lockDir !== '');
        $lockPath = $lockDir.'/pmss-user-web-root-'.$this->user.'.lock';
        $handle = pmssLockFileAcquire($lockPath, true);
        $this->assertTrue(is_resource($handle));
        try {
            $messages = [];
            $this->assertFalse(pmssUserReconcileWebRoot($this->context(), $this->logger($messages)));
            $this->assertFalse(is_dir($this->home.'/www'));
            $this->assertTrue($this->pmssMessagesContain($messages, 'per-user lock is busy'));
        } finally {
            pmssLockHandleRelease($handle);
        }
    }
    private function context(): array { return ['user' => $this->user, 'home' => $this->home]; }
    private function logger(array &$messages): callable { return static function (string $message) use (&$messages): void { $messages[] = $message; }; }

    private function pmssReconcileSummary(array $messages): string
    {
        foreach ($messages as $message) {
            if (strpos($message, 'web_root_reconcile ') !== false) {
                return $message;
            }
        }
        $this->fail('Expected a web-root reconciliation summary log line');
        return '';
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

    private function pmssSeedSkeleton(): void
    {
        $this->pmssWriteFile($this->skeleton.'/index.php', 'panel');
        $this->pmssWriteFile($this->skeleton.'/rutorrent/index.html', 'rutorrent');
        $this->pmssWriteFile($this->skeleton.'/rutorrent/conf/config.php', 'skeleton-config');
        $this->pmssCreateSymlinkOrSkip('../data', $this->skeleton.'/data');
    }
    private function pmssSeedRutorrentTemplates(): void
    {
        $this->pmssWriteFile($this->config.'/template.rutorrent.config', '$scgi_host = "";');
        $this->pmssWriteFile($this->config.'/template.rutorrent.access', 'access');
    }
}
