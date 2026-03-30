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
            "getenv('PMSS_HOME_DIR') ?: '/home'",
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

    public function testHelperKeepsReplaceAndInsertBranches(): void
    {
        $this->assertStringContainsString("'/^Connection\\\\\\\\GlobalUPLimit=.*/m'", $this->source);
        $this->assertStringContainsString("'/^Connection\\\\\\\\GlobalUPLimit=.*\\\\n?/m'", $this->source);
        $this->assertStringContainsString("'/(\\\\[Preferences\\\\][^\\\\[]*)/s'", $this->source);
        $this->assertStringContainsString('\'$1\'.$replacement', $this->source);
        $this->assertStringContainsString('"\\n", $config, 1);', $this->source);
    }
}
