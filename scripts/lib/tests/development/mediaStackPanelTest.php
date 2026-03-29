<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/mediaStackPanel.php';

class MediaStackPanelTest extends TestCase
{
    public function testStatusIsReadyForFreshHome(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-ready-');
        @file_put_contents($home.'/install-media-stack.sh', "#!/bin/bash\nexit 0\n");

        $status = \pmssMediaStackPanelStatusRead($home, 'alice', 'seedbox.example');

        $this->assertSame('ready', $status['state']);
        $this->assertTrue($status['canStart']);
    }

    public function testStatusBlocksWhenBinAlreadyPopulated(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-blocked-bin-');
        @mkdir($home.'/.bin', 0755, true);
        @file_put_contents($home.'/install-media-stack.sh', "#!/bin/bash\nexit 0\n");
        @file_put_contents($home.'/.bin/existing', '1');

        $status = \pmssMediaStackPanelStatusRead($home, 'alice', 'seedbox.example');

        $this->assertSame('blocked', $status['state']);
        $this->assertFalse($status['canStart']);
    }

    public function testStatusShowsInstalledUrlsWhenJellyfinConfigExists(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-installed-');
        @mkdir($home.'/.config/jellyfin/config', 0755, true);
        @file_put_contents($home.'/install-media-stack.sh', "#!/bin/bash\nexit 0\n");
        @file_put_contents($home.'/.config/jellyfin/config/network.xml', '<NetworkConfiguration />');

        $status = \pmssMediaStackPanelStatusRead($home, 'alice', 'seedbox.example');

        $this->assertSame('installed', $status['state']);
        $this->assertStringContainsString('/public-alice/jellyfin/web/index.html', $status['urls']['Jellyfin']);
    }

    public function testStatusShowsRunningWhenPidExists(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-running-');
        @file_put_contents($home.'/install-media-stack.sh', "#!/bin/bash\nexit 0\n");
        @file_put_contents($home.'/.install-media-stack-web.pid', (string) getmypid());

        $status = \pmssMediaStackPanelStatusRead($home, 'alice', 'seedbox.example');

        $this->assertSame('running', $status['state']);
        $this->assertTrue($status['poll']);
    }

    public function testStatusShowsFailedLogWhenPreviousRunStopped(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-failed-');
        @file_put_contents($home.'/install-media-stack.sh', "#!/bin/bash\nexit 0\n");
        @file_put_contents($home.'/.install-media-stack.log', "[ERR ] Download failed\nPartial output\n");

        $status = \pmssMediaStackPanelStatusRead($home, 'alice', 'seedbox.example');

        $this->assertSame('failed', $status['state']);
        $this->assertStringContainsString('Download failed', $status['tail']);
    }

    public function testHtmlIncludesWizardPasswordNotice(): void
    {
        $html = \pmssMediaStackPanelHtmlBuild(array(
            'state' => 'installed',
            'message' => 'Media stack is installed for this account.',
            'details' => array('No password is pre-generated. Create the Jellyfin admin account in the first-run wizard.'),
            'tail' => '',
            'urls' => array('Jellyfin' => 'https://seedbox.example/public-alice/jellyfin/web/index.html'),
        ));

        $this->assertStringContainsString('first-run wizard', $html);
        $this->assertStringContainsString('public-alice/jellyfin', $html);
    }

    public function testStartCommandIncludesPidFileAndScriptPath(): void
    {
        $home = '/home/alice';
        $command = \pmssMediaStackPanelStartCommandBuild($home, 'alice');

        $this->assertStringContainsString('.install-media-stack-web.pid', $command);
        $this->assertStringContainsString('install-media-stack.sh', $command);
        $this->assertStringContainsString("USER='alice'", $command);
    }
}
