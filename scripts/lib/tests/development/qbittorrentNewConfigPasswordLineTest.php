<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrent.php';
require_once __DIR__.'/../../user/qbittorrentWebUiAuth.php';

/** A qBittorrent.conf that userConfig creates always has a WebUI password line (ADR 0102). */
class qbittorrentNewConfigPasswordLineTest extends TestCase
{
    private function renderedTemplate(): string
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.qbittorrent.conf');
        return str_replace(['##username', '##port', '##uploadThrottleLine'], ['alice', '29000', ''], $template);
    }

    public function testUserConfigAddsThePasswordLineBeforeWritingANewConfig(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/util/userConfig.php', [
            "'qbittorrentWebUiAuth'",
            '$qbittorrentTemplate',
            '$qbittorrentConfig = pmssQbittorrentWebUiAuthForce($qbittorrentConfig);',
            'pmssReplaceUserFileWithMetadata($qbittorrentConfigFile, $qbittorrentConfig,',
        ]);
    }

    public function testTheRenderedTemplateGetsTheSentinelAndNoOtherPassword(): void
    {
        $config = pmssQbittorrentWebUiAuthForce($this->renderedTemplate());
        $this->assertSame(1, substr_count($config, 'WebUI\\Password_PBKDF2='), 'exactly one password line');
        $this->assertStringContainsString('WebUI\\Password_PBKDF2='.pmssQbittorrentWebUiAuthSentinel(), $config);
        $this->assertStringContainsString('WebUI\\LocalHostAuth=true', $config);
    }

    public function testAnAccountPasswordReplacesTheSentinel(): void
    {
        $config = pmssQbittorrentWebUiAuthForce($this->renderedTemplate());
        $hash = pmssQbittorrentPasswordHashGenerate('correct horse battery');
        $config = pmssQbittorrentConfigUpsert($config, 'Preferences', 'WebUI\\Password_PBKDF2', $hash);
        $this->assertSame(1, substr_count($config, 'WebUI\\Password_PBKDF2='), 'still exactly one password line');
        $this->assertStringContainsString('WebUI\\Password_PBKDF2='.$hash, $config);
        $this->assertStringNotContainsString(pmssQbittorrentWebUiAuthSentinel(), $config);
    }
}
