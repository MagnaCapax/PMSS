<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users.php';

/** Hermetic coverage for ruTorrent version-replacement state preservation. */
class UserRutorrentUpgradeTest extends TestCase
{
    private $home;
    private $skeleton;
    private $config;
    private $user = 'rutorrentupgrade';

    protected function setUp(): void
    {
        if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
            $this->user = 'root';
        }
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-rutorrent-upgrade-');
        $this->home = $this->pmssUserHomePath($homeRoot, $this->user);
        $this->skeleton = $this->pmssMakeTempDir('pmss-rutorrent-upgrade-skel-');
        $this->config = $this->pmssMakeTempDir('pmss-rutorrent-upgrade-config-');
        $this->pmssTrackEnvOverrides([
            'PMSS_SKEL_DIR' => $this->skeleton,
            'PMSS_CONFIG_DIR' => $this->config,
        ]);
        $this->pmssWriteFile($this->config.'/template.rutorrent.config', '$scgi_host = "";');
        $this->pmssWriteFile($this->config.'/template.rutorrent.access', 'access');
    }

    public function testUpgradeKeepsDurableShareLinkAndRssState(): void
    {
        $this->seedUpgradeTrees();
        $share = $this->home.'/www/rutorrent/share';
        $this->pmssWriteFile($share.'/settings/rss.dat', 'rss-feeds');
        $this->pmssWriteFile($share.'/users/'.$this->user.'/settings/rss/rssfilters.dat', 'rss-filters');
        pmssUserMigrateWebRootState($this->context());

        pmssUserUpgradeRutorrent($this->context());

        $durable = $this->home.'/.local/share/pmss/rutorrent/share';
        $this->assertTrue(is_link($share));
        $this->assertSame('../../.local/share/pmss/rutorrent/share', readlink($share));
        $this->assertSame('rss-feeds', file_get_contents($durable.'/settings/rss.dat'));
        $this->assertSame('rss-filters', file_get_contents($durable.'/users/'.$this->user.'/settings/rss/rssfilters.dat'));
        $this->assertFalse(file_exists($durable.'/settings/.gitignore'));
    }

    public function testUpgradeCopiesUnmigratedShareIncludingHiddenState(): void
    {
        $this->seedUpgradeTrees();
        $share = $this->home.'/www/rutorrent/share';
        $this->pmssWriteFile($share.'/settings/rss.dat', 'rss-feeds');
        $this->pmssWriteFile($share.'/.hidden-state', 'hidden');

        pmssUserUpgradeRutorrent($this->context());

        $this->assertTrue(is_dir($share) && !is_link($share));
        $this->assertSame('rss-feeds', file_get_contents($share.'/settings/rss.dat'));
        $this->assertSame('hidden', file_get_contents($share.'/.hidden-state'));
    }

    public function testUpgradeRejectsUnexpectedShareSymlinkBeforeMutation(): void
    {
        $this->seedUpgradeTrees();
        $outside = $this->pmssMakeTempDir('pmss-rutorrent-upgrade-outside-');
        $this->pmssWriteFile($outside.'/rss.dat', 'outside-state');
        $this->pmssCreateSymlinkOrSkip($outside, $this->home.'/www/rutorrent/share');

        $this->assertThrowsRuntime(function (): void {
            pmssUserUpgradeRutorrent($this->context());
        }, 'unexpected share symlink');

        $this->assertFalse(file_exists($this->home.'/www/oldRutorrent-3'));
        $this->assertSame('old-rutorrent', file_get_contents($this->home.'/www/rutorrent/index.html'));
        $this->assertSame('outside-state', file_get_contents($outside.'/rss.dat'));
    }

    public function testUpgradeAbortsWhenLegacyShareCannotBeRestored(): void
    {
        $this->seedUpgradeTrees(true);
        $this->pmssWriteFile($this->home.'/www/rutorrent/share/settings/rss.dat', 'rss-feeds');

        $this->assertThrowsRuntime(function (): void {
            pmssUserUpgradeRutorrent($this->context());
        }, 'Unable to restore ruTorrent share state');

        $this->assertSame('rss-feeds', file_get_contents($this->home.'/www/oldRutorrent-3/share/settings/rss.dat'));
        $this->assertFalse(file_exists($this->home.'/www/rutorrent/share/settings/rss.dat'));
    }

    private function context(): array
    {
        return [
            'user' => $this->user,
            'home' => $this->home,
            'user_esc' => escapeshellarg($this->user),
            'rutorrent_index_sha' => sha1('new-rutorrent'),
        ];
    }

    /** Seed old and skeleton ruTorrent trees; optionally make new share invalid. */
    private function seedUpgradeTrees(bool $skeletonShareAsFile = false): void
    {
        $this->pmssWriteFile($this->home.'/www/rutorrent/index.html', 'old-rutorrent');
        $this->pmssWriteFile($this->home.'/www/rutorrent/conf/config.php', 'old-config');
        $this->pmssWriteFile($this->skeleton.'/www/rutorrent/index.html', 'new-rutorrent');
        $this->pmssWriteFile($this->skeleton.'/www/rutorrent/conf/config.php', 'new-config');
        $share = $this->skeleton.'/www/rutorrent/share';
        if ($skeletonShareAsFile) {
            $this->pmssWriteFile($share, 'invalid-share');
            return;
        }
        $this->pmssWriteFile($share.'/settings/.gitignore', 'skeleton-share');
    }
}
