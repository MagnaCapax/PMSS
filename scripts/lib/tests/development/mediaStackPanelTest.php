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
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/mediaStack.php', [
            "strpos((string) \$_POST['action'], 'confirm-secure-') === 0",
            'pmssMediaStackPanelSecureHandle($home, $username, $hostname);',
            "elseif (\$action === 'start-stopped')",
            'pmssMediaStackPanelRecoveryHandle($home, $username, $hostname);',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/welcome.php', [
            'pmssMediaStackStartStopped',
            'pmssMediaStackSecureApp',
            "data: {action: 'confirm-secure-' + app}",
            "headers: {'X-Requested-With': 'XMLHttpRequest'}",
            'value="Start stopped apps"',
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

    public function testStatusOnlyShowsInstalledUrlsWhenJellyfinConfigExists(): void
    {
        $status = $this->mediaStatusFixture('pmss-media-installed-', '.config/jellyfin/config/network.xml', '<NetworkConfiguration />');

        $this->assertSame('installed', $status['state']);
        $this->assertStringContainsString('/public-alice/jellyfin/web/index.html', $status['urls']['Jellyfin']);
        $this->assertSame(array('Jellyfin'), array_keys($status['urls']));
    }

    public function testStatusShowsUrlsForEachAppMarkerShape(): void
    {
        $cases = array(
            array('Radarr', '.config/radarr', 'dir'),
            array('Sonarr', '.bin/Sonarr/Sonarr.dll', 'file'),
            array('Prowlarr', '.config/prowlarr', 'dir'),
            array('SABnzbd', '.bin/sabnzbd/sabnzbd/SABnzbd.py', 'file'),
        );
        foreach ($cases as $index => $case) {
            [$label, $marker, $type] = $case;
            $home = $this->mediaHomeCreate('pmss-media-app-marker-'.$index.'-');
            $this->pmssWriteRelativeFile($home, '.config/jellyfin/config/network.xml', '<NetworkConfiguration />');
            if ($type === 'dir') {
                $this->pmssEnsureDir($home.'/'.$marker);
            } else {
                $this->pmssWriteRelativeFile($home, $marker, 'marker');
            }

            $status = $this->mediaStatusRead($home);

            $this->assertTrue(isset($status['urls'][$label]), $label.' marker should expose its URL.');
        }
    }

    public function testStatusSkipsAutobrrMarkerWithoutProxyFragment(): void
    {
        $home = $this->mediaHomeCreate('pmss-media-autobrr-marker-');
        $this->pmssWriteRelativeFile($home, '.config/jellyfin/config/network.xml', '<NetworkConfiguration />');
        $this->pmssWriteRelativeFile($home, '.bin/autobrr/autobrr', 'marker');

        $status = $this->mediaStatusRead($home);

        $this->assertFalse(isset($status['urls']['Autobrr']));
    }

    public function testStatusShowsAutobrrUrlFromProxyFragmentWithoutInstallMarkers(): void
    {
        $home = $this->mediaHomeCreate('pmss-media-autobrr-proxy-');
        $this->pmssWriteRelativeFile($home, '.config/jellyfin/config/network.xml', '<NetworkConfiguration />');
        $this->pmssWriteRelativeFile(
            $home,
            '.lighttpd/custom.d/autobrr-custom.conf',
            '$HTTP["url"] =~ "^/autobrr(?:/|$)" { "map-urlpath" => ( "/autobrr" => "" ) }'
        );

        $status = $this->mediaStatusRead($home);

        $this->assertSame('https://seedbox.example/public-alice/autobrr/', $status['urls']['Autobrr']);
    }

    public function testSecurityStatusDetectsConfiguredAuthPerApp(): void
    {
        $home = $this->mediaHomeCreate('pmss-media-auth-protected-');
        $this->pmssWriteRelativeFile($home, '.config/radarr/config.xml', "<Config>\n<AuthenticationMethod>Forms</AuthenticationMethod>\n<AuthenticationRequired>Enabled</AuthenticationRequired>\n</Config>\n");
        $this->pmssWriteRelativeFile($home, '.bin/Radarr/Radarr.dll', 'marker');
        $this->pmssWriteRelativeFile($home, '.config/sabnzbd/sabnzbd.ini', "[misc]\nusername = alice\npassword = secret\n");
        $this->pmssWriteRelativeFile($home, '.bin/sabnzbd/sabnzbd/SABnzbd.py', 'marker');
        $this->pmssWriteRelativeFile($home, '.config/jellyfin/config/network.xml', '<NetworkConfiguration />');
        $this->pmssWriteRelativeFile($home, '.config/jellyfin/config/system.xml', '<IsStartupWizardCompleted>true</IsStartupWizardCompleted>');
        $this->pmssWriteRelativeFile($home, '.bin/jellyfin/jellyfin.dll', 'marker');

        $status = $this->mediaStatusRead($home);

        $this->assertTrue($status['security']['radarr']['protected']);
        $this->assertTrue($status['security']['sabnzbd']['protected']);
        $this->assertTrue($status['security']['jellyfin']['protected']);
        $this->assertSame('Protected', $status['security']['radarr']['status']);
    }

    public function testSecurityStatusShowsExposedActionForUnprotectedApp(): void
    {
        $home = $this->mediaHomeCreate('pmss-media-auth-exposed-');
        $this->pmssWriteRelativeFile($home, '.config/sonarr/config.xml', "<Config>\n<AuthenticationMethod>None</AuthenticationMethod>\n<AuthenticationRequired>Disabled</AuthenticationRequired>\n</Config>\n");
        $this->pmssWriteRelativeFile($home, '.bin/Sonarr/Sonarr.dll', 'marker');

        $status = $this->mediaStatusRead($home);
        $html = \pmssMediaStackPanelHtmlBuild($status);

        $this->assertFalse($status['security']['sonarr']['protected']);
        $this->assertSame('confirm-secure-sonarr', $status['security']['sonarr']['action']);
        $this->assertStringContainsAllStrings(['Exposed', 'Secure this app', "pmssMediaStackSecureApp(this, 'sonarr')"], $html);
    }

    public function testSecurityStatusDetectsAutobrrSqliteUser(): void
    {
        if (!class_exists('SQLite3')) {
            throw new SkipTest('SQLite3 helper not present in this PHP runtime');
        }

        $home = $this->mediaHomeCreate('pmss-media-auth-autobrr-');
        $this->pmssWriteRelativeFile(
            $home,
            '.lighttpd/custom.d/autobrr-custom.conf',
            '$HTTP["url"] =~ "^/autobrr(?:/|$)" { "map-urlpath" => ( "/autobrr" => "" ) }'
        );
        $this->pmssWriteRelativeFile($home, '.bin/autobrr/autobrr', 'marker');
        $this->pmssWriteRelativeFile($home, '.bin/autobrr/autobrrctl', 'marker');
        $this->pmssEnsureDir($home.'/.config/autobrr');
        $db = new \SQLite3($home.'/.config/autobrr/autobrr.db');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $db->exec("INSERT INTO users (username) VALUES ('alice')");
        $db->close();

        $status = $this->mediaStatusRead($home);

        $this->assertTrue($status['security']['autobrr']['protected']);
    }

    public function testStatusShowsRuntimeAppsWhenWatchdogSnapshotExists(): void
    {
        $home = $this->mediaHomeCreate('pmss-media-runtime-status-');
        $this->pmssWriteRelativeFile($home, '.config/jellyfin/config/network.xml', '<NetworkConfiguration />');
        $this->pmssWriteRelativeFile($home, '.bashrc.custom', "alias sonarr='true'\n");
        $this->pmssWriteRelativeFile($home, '.media-stack-status.json', json_encode(array(
            'state' => 'degraded',
            'apps' => array(
                'sonarr' => array('state' => 'running', 'consecutiveFailures' => 0),
                'radarr' => array('state' => 'failed', 'consecutiveFailures' => 3),
            ),
        )));

        $status = $this->mediaStatusRead($home);

        $this->assertSame('degraded', $status['state']);
        $this->assertTrue($status['canRestart']);
        $this->assertStringContainsString('Radarr: failed repeatedly (3 consecutive failed checks).', implode(' ', $status['details']));
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

    public function testHtmlIncludesMediaStackCredentialsNotice(): void
    {
        $html = \pmssMediaStackPanelHtmlBuild(array(
            'state' => 'installed',
            'message' => 'Media stack is installed for this account.',
            'details' => array('Use the app-level credentials in ~/.media-stack-credentials.txt; exposed apps can be secured from this panel.'),
            'tail' => '',
            'urls' => array('Jellyfin' => 'https://seedbox.example/public-alice/jellyfin/web/index.html'),
        ));

        $this->assertStringContainsAllStrings(['.media-stack-credentials.txt', 'public-alice/jellyfin'], $html);
    }

    public function testStartCommandIncludesPidFileAndScriptPath(): void
    {
        $home = '/home/alice';
        $command = \pmssMediaStackPanelStartCommandBuild($home, 'alice');

        $this->assertStringContainsAllStrings(['.install-media-stack-web.pid', 'install-media-stack.sh', "USER='alice'"], $command);
    }

    public function testRecoveryCommandUsesFixedInstallerMode(): void
    {
        $command = \pmssMediaStackPanelRecoveryCommandBuild('/home/alice', 'alice');

        $this->assertStringContainsAllStrings([
            "HOME='/home/alice'",
            "USER='alice'",
            "'/home/alice/install-media-stack.sh' --start-stopped",
            'pmss-media-stack-started',
        ], $command);
    }

    public function testSecureActionAndCommandUseHardcodedAppAllowlist(): void
    {
        $this->assertSame('radarr', \pmssMediaStackPanelSecureActionAppIdRead('confirm-secure-radarr'));
        $this->assertSame(null, \pmssMediaStackPanelSecureActionAppIdRead('confirm-secure-../../evil'));

        $command = \pmssMediaStackPanelSecureCommandBuild('/home/alice', 'alice', 'radarr');
        $this->assertStringContainsAllStrings([
            "HOME='/home/alice'",
            "USER='alice'",
            "'/home/alice/install-media-stack.sh'",
            "'--secure-app=radarr'",
        ], $command);
        $this->assertStringContainsAndOmitsStrings([], array('&&' => $command, ';' => $command, '||' => $command), $command);
        $this->assertSame('', \pmssMediaStackPanelSecureCommandBuild('/home/alice', 'alice', '../../evil'));
    }

    public function testRecoveryRequestRequiresAjaxPost(): void
    {
        $this->assertTrue(\pmssMediaStackPanelRecoveryRequestAllowed(array(
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        )));
        foreach (array(
            array(),
            array('REQUEST_METHOD' => 'GET', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'),
            array('REQUEST_METHOD' => 'POST'),
            array('REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'cross-site-form'),
        ) as $server) {
            $this->assertFalse(\pmssMediaStackPanelRecoveryRequestAllowed($server));
        }
    }

    public function testRecoveryGateRejectsMissingAndSymlinkedAliases(): void
    {
        $home = $this->mediaHomeCreate('pmss-media-recovery-gate-');
        $this->assertFalse(\pmssMediaStackPanelRecoveryGateRead($home)['ok']);

        $target = $this->pmssWriteRelativeFile($home, '.bashrc.custom-target', "alias sonarr='true'\n");
        @symlink($target, $home.'/.bashrc.custom');
        $this->assertFalse(\pmssMediaStackPanelRecoveryGateRead($home)['ok']);

        @unlink($home.'/.bashrc.custom');
        $this->pmssWriteRelativeFile($home, '.bashrc.custom', "alias sonarr='true'\n");
        $this->assertTrue(\pmssMediaStackPanelRecoveryGateRead($home)['ok']);
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
