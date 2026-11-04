<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/hermesAgent.php';

final class HermesAgentPanelTest extends TestCase
{
    public function testStatusReadsOnlyBoundedAccountFiles(): void
    {
        $home = $this->pmssMakeTempDir('pmss-hermes-panel-');
        $this->assertFalse(\pmssHermesPanelStatusRead($home)['installed']);
        $this->pmssWriteFile($home.'/.hermes/pmss-install.status', "failed\n");
        $this->assertSame('failed', \pmssHermesPanelStatusRead($home)['state']);
        $this->assertSame('', \pmssHermesPanelStatusRead($home)['reason']);
        foreach (array('files', 'quota', 'error') as $reason) {
            $this->pmssWriteFile($home.'/.hermes/pmss-install.reason', $reason."\n");
            $this->assertSame($reason, \pmssHermesPanelStatusRead($home)['reason']);
        }
        $this->pmssWriteFile($home.'/.hermes/pmss-install.reason', "<b>\n");
        $this->assertSame('', \pmssHermesPanelStatusRead($home)['reason']);
        // The Configure text names the installer's own file requirement.
        require_once dirname(__DIR__, 4).'/etc/skel/bin/pmssHermesRuntime.php';
        $this->assertStringContainsString(number_format(HERMES_REQUIRED_FILES).' free files',
            $this->pmssReadRepoFile('etc/skel/www/configure.php'));
        $url = 'https://node.example.com/user-'.basename($home).'/hermes/';
        $this->pmssWriteFile($home.'/.hermes/pmss-dashboard.url', $url."\n");
        $this->assertSame($url, \pmssHermesPanelStatusRead($home)['url']);
        $this->pmssWriteFile($home.'/.hermes/pmss-dashboard.url', "https://wrong.example.test/\n");
        $this->assertSame('', \pmssHermesPanelStatusRead($home)['url']);
        $this->pmssWriteFile($home.'/.hermes/pmss-install.status', "ready\n");
        $this->pmssWriteFile($home.'/.hermes/pmss-dashboard.url', "https://0123456789abcdef.mcx.fi:846/\n");
        $this->assertSame('update-required', \pmssHermesPanelStatusRead($home)['state']);
        $this->assertFalse(\pmssHermesPanelStatusRead($home)['installed']);
    }

    public function testUnknownActionAndLinkedInstallerRefuseLaunch(): void
    {
        $home = $this->pmssMakeTempDir('pmss-hermes-panel-');
        $this->assertSame(400, \pmssHermesPanelAction($home, 'erase', array())['status']);
        $this->assertSame(409, \pmssHermesPanelAction($home, 'install', array())['status']);
        $target = $this->pmssWriteFile($home.'/target', 'fixture');
        $this->pmssEnsureDir($home.'/bin');
        $this->pmssCreateSymlinkOrSkip($target, $home.'/bin/install-hermes');
        $this->assertSame(409, \pmssHermesPanelAction($home, 'install', array())['status']);
    }
}
