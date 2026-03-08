<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
putenv('PMSS_RTORRENT_NO_ENTRYPOINT=1');
require_once dirname(__DIR__, 2).'/update/apps/rtorrent.php';

class RtorrentTargetVersionTest extends TestCase
{
    /**
     * Debian 10+ must use the udns build targets.
     */
    public function testUsesUdnsTargetsOnDebianTenAndNewer(): void
    {
        $targets = \pmssRtorrentResolveTargetVersions(['version' => 12], '12.5');

        $this->assertEquals('0.9.8-udns', $targets['rtorrent']);
        $this->assertEquals('0.13.8-udns', $targets['libtorrent']);
    }

    /**
     * Debian 9 and older keep the legacy fallback build targets.
     */
    public function testUsesLegacyTargetsOnDebianNineAndOlder(): void
    {
        $targets = \pmssRtorrentResolveTargetVersions(['version' => 9], '9.13');

        $this->assertEquals('0.9.6', $targets['rtorrent']);
        $this->assertEquals('0.13.6', $targets['libtorrent']);
    }

    /**
     * Legacy /etc/debian_version fallback remains available for unknown distro data.
     */
    public function testFallsBackToLegacyDebianVersionParsing(): void
    {
        $targets = \pmssRtorrentResolveTargetVersions(['version' => 0], '10.13');

        $this->assertEquals('0.9.8-udns', $targets['rtorrent']);
        $this->assertEquals('0.13.8-udns', $targets['libtorrent']);
    }
}

