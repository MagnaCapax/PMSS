<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class QbittorrentThrottleHelperTest extends TestCase
{
    /** @var string */
    private $source = '';

    protected function setUp(): void
    {
        $this->source = (string) file_get_contents(dirname(__DIR__, 2).'/user/qbittorrent.php');
    }

    public function testHelperKeepsCanonicalConfigPath(): void
    {
        $this->assertStringContainsString(
            "function pmssQbittorrentConfigPath(string \$username): string",
            $this->source
        );
        $this->assertStringContainsString(
            "pmssDirPathResolve(null, 'PMSS_HOME_DIR', '/home')",
            $this->source
        );
    }

    public function testHelperFallsBackToStoredThrottleWhenArgumentOmitted(): void
    {
        $this->assertStringContainsString(
            '$throttle = $throttle ?? pmssReadTorrentThrottle($username);',
            $this->source
        );
    }

    public function testHelperSharesOneConfigMutationPath(): void
    {
        $this->assertStringContainsString('function pmssQbittorrentConfigMutate(', $this->source);
        $this->assertStringContainsString('pmssQbittorrentConfigUpsert(', $this->source);
        $this->assertStringContainsString("'/^Connection\\\\\\\\GlobalUPLimit=.*\\r?\\n?/m'", $this->source);
        $this->assertTrue(
            strpos($this->source, "'/(\\\\[Preferences\\\\][^\\\\[]*)/s'") === false,
            'Upload throttle writes should reuse the shared qBittorrent section upsert path'
        );
    }
}
