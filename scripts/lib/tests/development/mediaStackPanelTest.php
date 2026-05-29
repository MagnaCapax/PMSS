<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/userMediaStackPanel.php';

class MediaStackPanelTest extends TestCase
{
    private function mediaHomeCreate(string $prefix): string
    {
        $home = $this->pmssMakeTempDir($prefix);
        @file_put_contents($home.'/install-media-stack.sh', "#!/bin/bash\nexit 0\n");
        return $home;
    }

    public function testEndpointRequiresCustomerTreeHelper(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/skel/www/mediaStack.php', [
            "require_once __DIR__.'/userMediaStackPanel.php';",
            '$username = basename(rtrim($home, \'/\'));',
        ], ['/scripts/lib/user/mediaStackPanel.php']);
        $this->pmssAssertRepoFileNotContainsStrings('etc/skel/www/userMediaStackPanel.php', [
            'function pmssMediaStackPanelCurrent'.'UserRead',
            'function pmssMediaStackPanelCurrent'.'HostnameRead',
            'function pmssMediaStackPanelShellExec'.'Available',
            'function pmssMediaStackPanel'.'Installed',
        ]);
    }

    public function testStatusIsReadyForFreshHome(): void
    {
        $home = $this->mediaHomeCreate('pmss-media-ready-');

        $status = \pmssMediaStackPanelStatusRead($home, 'alice', 'seedbox.example');

        $this->assertSame('ready', $status['state']);
        $this->assertTrue($status['canStart']);
    }

    public function testStatusBlocksWhenBinAlreadyPopulated(): void
    {
        $home = $this->mediaHomeCreate('pmss-media-blocked-bin-');
        @mkdir($home.'/.bin', 0755, true);
        @file_put_contents($home.'/.bin/existing', '1');

        $status = \pmssMediaStackPanelStatusRead($home, 'alice', 'seedbox.example');

        $this->assertSame('blocked', $status['state']);
        $this->assertFalse($status['canStart']);
    }

    public function testStartGateReportsMissingInstallerBeforePopulatedBin(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-gate-priority-');
        @mkdir($home.'/.bin', 0755, true);
        @file_put_contents($home.'/.bin/existing', '1');

        $gate = \pmssMediaStackPanelStartGateRead($home);

        $this->assertFalse($gate['ok']);
        $this->assertSame('Media stack installer is missing from this account.', $gate['message']);
    }

    public function testStatusShowsInstalledUrlsWhenJellyfinConfigExists(): void
    {
        $status = $this->mediaStatusFixture('pmss-media-installed-', '.config/jellyfin/config/network.xml', '<NetworkConfiguration />');

        $this->assertSame('installed', $status['state']);
        $this->assertStringContainsString('/public-alice/jellyfin/web/index.html', $status['urls']['Jellyfin']);
    }

    public function testStatusShowsRunningWhenPidExists(): void
    {
        $status = $this->mediaStatusFixture('pmss-media-running-', '.install-media-stack-web.pid', (string) getmypid());

        $this->assertSame('running', $status['state']);
        $this->assertTrue($status['poll']);
    }

    public function testStatusShowsFailedLogWhenPreviousRunStopped(): void
    {
        $status = $this->mediaStatusFixture('pmss-media-failed-', '.install-media-stack.log', "[ERR ] Download failed\nPartial output\n");

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

    public function testHomePathBuildsStableInstallerPaths(): void
    {
        $home = '/home/alice/';

        $this->assertSame('/home/alice/install-media-stack.sh', \pmssMediaStackPanelHomePath($home, 'install-media-stack.sh'));
        $this->assertSame('/home/alice/.install-media-stack.log', \pmssMediaStackPanelHomePath($home, '.install-media-stack.log'));
        $this->assertSame('/home/alice/.install-media-stack-web.pid', \pmssMediaStackPanelHomePath($home, '.install-media-stack-web.pid'));
    }

    private function mediaStatusFixture(string $prefix, string $relativePath, string $content): array
    {
        $home = $this->mediaHomeCreate($prefix);
        $this->pmssWriteRelativeFile($home, $relativePath, $content);

        return \pmssMediaStackPanelStatusRead($home, 'alice', 'seedbox.example');
    }
}
