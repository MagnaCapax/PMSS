<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PanelUrlUserinfoTest extends TestCase
{
    public function testCustomerEntryHeadsStripUrlUserinfo(): void
    {
        $script = "<script>var u = new URL(location.href); if (u.username || u.password) { u.username = ''; u.password = ''; location.replace(u.href); }</script>";
        foreach (array(
            'index.php' => 1, 'welcome.php' => 1, 'apps.php' => 2,
            'configure.php' => 1, 'info.php' => 1, 'filemanager.php' => 2,
            'console.php' => 2, 'panelSessionPage.php' => 1,
        ) as $file => $heads) {
            $source = $this->pmssReadRepoFile('etc/skel/www/'.$file);
            $source = str_replace("\\'", "'", $source);
            $this->assertSame($heads, substr_count($source, $script), $file.' must clean each HTML head.');
        }
    }

    public function testCustomerPhpDoesNotBuildCredentialUrls(): void
    {
        foreach (glob($this->pmssRepoPath('etc/skel/www/*.php')) ?: array() as $path) {
            $source = (string) file_get_contents($path);
            $this->assertFalse((bool) preg_match('~https?://[^\s\x27"<>/]+:[^\s\x27"<>/]+@~i', $source), basename($path));
            $this->assertFalse((bool) preg_match('~://[^\n;]{0,160}\$user(?:name)?[^\n;]{0,160}\$pass(?:word)?[^\n;]{0,160}@~i', $source), basename($path));
        }
    }
}
