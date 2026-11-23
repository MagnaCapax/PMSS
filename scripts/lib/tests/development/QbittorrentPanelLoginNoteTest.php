<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** The second qBittorrent sign-in after the panel login is explained where customers read about logins (#1272). */
final class QbittorrentPanelLoginNoteTest extends TestCase
{
    public function testBothLoginTextsExplainTheOwnSignInFormAndTheSameUsername(): void
    {
        foreach (array('etc/skel/www/configure.php', 'etc/skel/www/welcome.php') as $page) {
            $html = (string) file_get_contents($this->pmssRepoPath($page));
            $this->assertStringContainsString('qBittorrent shows its own sign-in form', $html, $page);
            $this->assertStringContainsString('the panel password is not passed to it; use the same username there', $html, $page);
        }
    }
}
