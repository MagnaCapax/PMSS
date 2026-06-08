<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/userMediaStackPanel.php';

class MediaStackPanelTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssTrackEnvOverrides($this->mediaStackCgroupV2Fixture(2 * 1024 * 1024 * 1024));
    }

    private function mediaHomeCreate(string $prefix): string
    {
        $home = $this->pmssMakeTempDir($prefix);
        @file_put_contents($home.'/install-media-stack.sh', "#!/bin/bash\nexit 0\n");
        return $home;
    }

    private function mediaStatusRead(string $home): array
    {
        return \pmssMediaStackPanelStatusRead($home, 'alice', 'seedbox.example');
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

        $status = $this->mediaStatusRead($home);

        $this->pmssAssertArraySubsetSame(['state' => 'ready', 'canStart' => true], $status);
    }

    public function testStatusBlocksWhenBinAlreadyPopulated(): void
    {
        $home = $this->mediaHomeCreate('pmss-media-blocked-bin-');
        @mkdir($home.'/.bin', 0755, true);
        @file_put_contents($home.'/.bin/existing', '1');

        $status = $this->mediaStatusRead($home);

        $this->pmssAssertArraySubsetSame(['state' => 'blocked', 'canStart' => false], $status);
    }

    public function testStatusHandlesMemoryLimitBoundaries(): void
    {
        foreach ([
            [512 * 1024 * 1024, 'blocked', false, 'install-media-stack.sh --force'],
            [2 * 1024 * 1024 * 1024, 'ready', true, ''],
        ] as [$limitBytes, $expectedState, $expectedCanStart, $messageNeedle]) {
            $this->assertMediaStatusForMemoryLimit($limitBytes, $expectedState, $expectedCanStart, $messageNeedle);
        }
    }

    public function testMemoryLimitReaderUsesParentV1CgroupLimit(): void
    {
        $root = $this->pmssMakeTempDir('pmss-media-cgroup-v1-');
        $cgroupFile = $this->pmssWriteFile($root.'/proc-self-cgroup', "8:memory:/user.slice/user-1000.slice/session.scope\n");
        $this->pmssWriteFile($root.'/memory/user.slice/user-1000.slice/memory.limit_in_bytes', (string) (768 * 1024 * 1024)."\n");

        $this->pmssWithEnv(array(
            'PMSS_MEDIA_STACK_CGROUP_FILE' => $cgroupFile,
            'PMSS_MEDIA_STACK_CGROUP_ROOT' => $root,
        ), function (): void {
            $this->assertSame(768 * 1024 * 1024, \pmssMediaStackPanelMemoryLimitBytesRead());
        });
    }

    public function testStartGateReportsMissingInstallerBeforePopulatedBin(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-gate-priority-');
        @mkdir($home.'/.bin', 0755, true);
        @file_put_contents($home.'/.bin/existing', '1');

        $gate = \pmssMediaStackPanelStartGateRead($home);

        $this->pmssAssertArraySubsetSame(['ok' => false, 'message' => 'Media stack installer is missing from this account.'], $gate);
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

        $this->pmssAssertArraySubsetSame(['state' => 'running', 'poll' => true], $status);
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

        $this->assertStringContainsAllStrings(['first-run wizard', 'public-alice/jellyfin'], $html);
    }

    public function testStartCommandIncludesPidFileAndScriptPath(): void
    {
        $home = '/home/alice';
        $command = \pmssMediaStackPanelStartCommandBuild($home, 'alice');

        $this->assertStringContainsAllStrings(['.install-media-stack-web.pid', 'install-media-stack.sh', "USER='alice'"], $command);
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

        return $this->mediaStatusRead($home);
    }

    private function mediaStackCgroupV2Fixture(int $limitBytes): array
    {
        $root = $this->pmssMakeTempDir('pmss-media-cgroup-v2-');
        $cgroupFile = $this->pmssWriteFile($root.'/proc-self-cgroup', "0::/user.slice/user-1000.slice/session.scope\n");
        $this->pmssWriteFile($root.'/user.slice/user-1000.slice/session.scope/memory.high', "max\n");
        $this->pmssWriteFile($root.'/user.slice/user-1000.slice/memory.high', (string) $limitBytes."\n");

        return array(
            'PMSS_MEDIA_STACK_CGROUP_FILE' => $cgroupFile,
            'PMSS_MEDIA_STACK_CGROUP_ROOT' => $root,
        );
    }

    private function assertMediaStatusForMemoryLimit(
        int $limitBytes,
        string $expectedState,
        bool $expectedCanStart,
        string $messageNeedle = ''
    ): void {
        $home = $this->mediaHomeCreate('pmss-media-memory-');

        $this->pmssWithEnv($this->mediaStackCgroupV2Fixture($limitBytes), function () use ($home, $expectedState, $expectedCanStart, $messageNeedle): void {
            $status = $this->mediaStatusRead($home);

            $this->pmssAssertArraySubsetSame(['state' => $expectedState, 'canStart' => $expectedCanStart], $status);
            if ($messageNeedle !== '') {
                $this->assertStringContainsString($messageNeedle, $status['message']);
            }
        });
    }
}
