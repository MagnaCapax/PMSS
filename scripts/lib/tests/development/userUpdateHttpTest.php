<?php
namespace {
    pmssTestInstallRunUserStepShim();
}

namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserUpdateHttpTest extends TestCase
{
    protected function setUp(): void
    {
        // Success fixtures use dry runs; the failure fixture injects the CLI result.
        // Keep the installed /scripts command out of the hermetic test path.
        $this->pmssTrackEnvOverrides(['PMSS_DRY_RUN' => '1']);
    }

    public function testFailedLighttpdRefreshContinuesRemainingHttpMaintenance(): void
    {
        $script = <<<'PHP'
namespace HttpRefreshFixture;
function pmssUserContextIdentity($ctx) { return [$ctx['user'], $ctx['home']]; }
function runUserStep(...$args) { return $GLOBALS['rc']; }
function pmssUserLog($user, $message) { $GLOBALS['logs'][] = $message; }
function pmssQbittorrentWebUiAuthEnsure(...$args) { $GLOBALS['continued'][] = 'qbittorrent-auth'; }
function pmssQbittorrentApplyManagedConfig(...$args) { $GLOBALS['continued'][] = 'qbittorrent'; return true; }
function pmssDelugeApplyManagedConfig(...$args) { $GLOBALS['continued'][] = 'deluge'; return true; }
function file_exists($path) { return true; }
function pmssUserFilePathIsSafe($path) { $GLOBALS['continued'][] = 'php.ini'; return false; }
function is_dir($path) { return false; }
function pmssEnsureUserHomeDir($user, $home, $path, ...$args) { $GLOBALS['continued'][] = $path; return false; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/users/http.php', 'HttpRefreshFixture');
        $script .= <<<'PHP'
$runs = [];
foreach ([['alice', 1], ['bob', 0], ['alice', 0]] as [$user, $rc]) {
    $GLOBALS['rc'] = $rc;
    $GLOBALS['logs'] = $GLOBALS['continued'] = [];
    pmssUserConfigureHttp(['user' => $user, 'home' => '/fixture/'.$user, 'user_esc' => escapeshellarg($user)]);
    $runs[] = ['continued' => $GLOBALS['continued'], 'logs' => implode("\n", $GLOBALS['logs']),
        'failed' => [pmssUserLighttpdRefreshFailed('alice'), pmssUserLighttpdRefreshFailed('bob')]];
}
echo json_encode($runs);
PHP;
        $runs = $this->pmssRunInlinePhpJson($script);
        foreach ($runs as $run) {
            $this->assertSame(['qbittorrent-auth', 'qbittorrent', 'deluge', 'php.ini', '.tmp', '.irssi', 'www/recycle'], $run['continued']);
        }
        $this->assertStringContainsString('[WARN] lighttpd configuration refresh failed (rc=1)', $runs[0]['logs']);
        $this->assertSame([true, false], $runs[0]['failed']);
        $this->assertSame([true, false], $runs[1]['failed'], 'clean account cannot clear another account failure');
        $this->assertSame([false, false], $runs[2]['failed'], 'a clean retry clears the prior failure');
    }

    public function testConfigureHttpRestoresQbittorrentWebUiProtections(): void
    {
        $home = $this->pmssMakeTempDir('pmss-http-qbittorrent-');
        mkdir($home.'/.config/qBittorrent', 0755, true);

        $config = "[Preferences]\n";
        $config .= "WebUI\\CSRFProtection=false\n";
        $config .= "WebUI\\ClickjackingProtection=false\n";
        $config .= "WebUI\\HostHeaderValidation=false\n";
        $config .= "WebUI\\Port=12345\n";
        file_put_contents($home.'/.config/qBittorrent/qBittorrent.conf', $config);

        $ctx = $this->pmssUserUpdateContext($home);

        \pmssUserConfigureHttp($ctx);

        $updated = file_get_contents($home.'/.config/qBittorrent/qBittorrent.conf');
        $updatedConfig = ($updated === false) ? '' : $updated;
        $this->assertStringContainsAllStrings(['WebUI\\CSRFProtection=true', 'WebUI\\ClickjackingProtection=true', 'WebUI\\HostHeaderValidation=false'], $updatedConfig);
        $this->assertTrue(strpos($updatedConfig, 'WebUI\\CSRFProtection=false') === false);
        $this->assertTrue(strpos($updatedConfig, 'WebUI\\ClickjackingProtection=false') === false);
        $this->assertTrue(strpos($updatedConfig, 'WebUI\\HostHeaderValidation=true') === false);
    }

    public function testConfigureHttpCreatesTempDirectory(): void
    {
        $tempHome = $this->pmssMakeTempDir('pmss-http-');
        mkdir($tempHome.'/.lighttpd', 0755, true);
        $original = $this->pmssReadRepoFile('etc/skel/.lighttpd/php.ini');
        file_put_contents($tempHome.'/.lighttpd/php.ini', $original);

        $ctx = $this->pmssUserUpdateContext($tempHome);

        \pmssUserConfigureHttp($ctx);

        $ini = file_get_contents($tempHome.'/.lighttpd/php.ini');
        $this->assertSame($original, $ini);
        $this->assertSame(parse_ini_string($original), parse_ini_file($tempHome.'/.lighttpd/php.ini'));
        $this->assertTrue(is_dir($tempHome.'/.tmp'));
    }

    public function testConfigureHttpRefusesSymlinkedPhpIni(): void
    {
        $tempHome = $this->pmssMakeTempDir('pmss-http-phpini-link-home-');
        $target = $this->pmssMakeTempFile('pmss-http-phpini-link-target-');
        mkdir($tempHome.'/.lighttpd', 0755, true);
        file_put_contents($target, "display_errors = On\n");
        $this->pmssCreateSymlinkOrSkip($target, $tempHome.'/.lighttpd/php.ini');

        $ctx = $this->pmssUserUpdateContext($tempHome);

        list(, $output) = $this->pmssCaptureStdout(function () use ($ctx): void {
            \pmssUserConfigureHttp($ctx);
        });

        $this->assertEquals("display_errors = On\n", (string) file_get_contents($target));
        $this->assertTrue(strpos($output, 'Updated php.ini for user dummy') === false);
    }

    public function testConfigureHttpRestoresQbittorrentManagedKeysWhenMissing(): void
    {
        $home = $this->pmssMakeTempDir('pmss-http-qbittorrent-missing-');
        @mkdir($home.'/.config/qBittorrent', 0755, true);

        $config = "[Preferences]\n";
        $config .= "WebUI\\Port=12345\n";
        $config .= "WebUI\\Address=*\n";
        file_put_contents($home.'/.config/qBittorrent/qBittorrent.conf', $config);

        $ctx = $this->pmssUserUpdateContext($home);

        \pmssUserConfigureHttp($ctx);

        $updated = file_get_contents($home.'/.config/qBittorrent/qBittorrent.conf');
        $updatedConfig = ($updated === false) ? '' : $updated;
        $this->assertStringContainsAllStrings(['WebUI\\Port=12345', 'WebUI\\Address=*', 'WebUI\\CSRFProtection=true', 'WebUI\\HostHeaderValidation=false', 'Downloads\\PreAllocation=true', 'Session\\DiskCacheSize=128'], $updatedConfig);
    }

    public function testConfigureHttpRefreshesDelugeManagedKeys(): void
    {
        $home = $this->pmssMakeTempDir('pmss-http-deluge-');
        @mkdir($home.'/.config/deluge', 0755, true);
        file_put_contents($home.'/.config/deluge/core.conf', <<<'JSON'
{
    "file": 1,
    "format": 1
}{
    "download_location": "/home/dummy/data",
    "enabled_plugins": [
        "Label"
    ],
    "max_active_downloading": 20,
    "max_active_limit": 999,
    "max_connections_global": 999,
    "max_upload_slots_global": 50
}
JSON
        );

        $ctx = $this->pmssUserUpdateContext($home);

        \pmssUserConfigureHttp($ctx);

        $updated = (string) file_get_contents($home.'/.config/deluge/core.conf');
        $this->assertStringContainsAllStrings(['"download_location": "/home/dummy/data"', '"enabled_plugins": [', '"Label"', '"max_active_downloading": 5', '"max_active_limit": 500', '"max_connections_global": 300', '"max_upload_slots_global": 15'], $updated);
    }

    public function testConfigureHttpUsesDefaultSkelPathForIrssiCopy(): void
    {
        $home = $this->pmssMakeTempDir('pmss-http-skel-default-');
        $ctx = $this->pmssUserUpdateContext($home);

        $jsonLog = $this->pmssMakeTempFile('pmss-json-');
        file_put_contents($jsonLog, '');

        $GLOBALS['PMSS_JSON_LOG_PATH'] = null;

        $cmd = null;
        try {
            $this->pmssWithEnv(['PMSS_DRY_RUN' => '1', 'PMSS_JSON_LOG' => $jsonLog, 'PMSS_SKEL_DIR' => null], function () use ($ctx, $jsonLog, &$cmd): void {
                \pmssUserConfigureHttp($ctx);
                $cmd = $this->pmssFindJsonStepCommand($jsonLog, 'Copying irssi skeleton config');
            });
        } finally {
            $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
        }

        $this->assertTrue(is_dir($home.'/.irssi'));
        $this->assertTrue($cmd === null || strpos($cmd, '/etc/skel/.irssi/config') !== false);
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/users/http.php',
            "pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/.irssi/config'");
    }

    public function testConfigureHttpSkipsIrssiCopyWhenDirectoryCannotBeCreated(): void
    {
        $home = $this->pmssMakeTempDir('pmss-http-irssi-conflict-');
        file_put_contents($home.'/.irssi', 'customer content');
        $jsonLog = $this->pmssMakeTempFile('pmss-json-');
        file_put_contents($jsonLog, '');

        $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
        try {
            $this->pmssWithEnv(['PMSS_DRY_RUN' => '1', 'PMSS_JSON_LOG' => $jsonLog], function () use ($home, $jsonLog): void {
                \pmssUserConfigureHttp($this->pmssUserUpdateContext($home));
                $this->assertSame(null, $this->pmssFindJsonStepCommand($jsonLog, 'Copying irssi skeleton config'));
                $this->assertSame('customer content', file_get_contents($home.'/.irssi'));
            });
        } finally {
            $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
        }
    }

    public function testConfigureHttpUsesSkelOverrideForIrssiCopy(): void
    {
        $home = $this->pmssMakeTempDir('pmss-http-skel-override-home-');
        $skel = $this->pmssMakeTempDir('pmss-http-skel-override-skel-');
        @mkdir($skel.'/.irssi', 0755, true);
        @file_put_contents($skel.'/.irssi/config', 'test');

        $ctx = $this->pmssUserUpdateContext($home);

        $jsonLog = $this->pmssMakeTempFile('pmss-json-');
        file_put_contents($jsonLog, '');

        $GLOBALS['PMSS_JSON_LOG_PATH'] = null;

        $cmd = null;
        try {
            $this->pmssWithEnv(['PMSS_DRY_RUN' => '1', 'PMSS_JSON_LOG' => $jsonLog, 'PMSS_SKEL_DIR' => $skel], function () use ($ctx, $jsonLog, &$cmd): void {
                \pmssUserConfigureHttp($ctx);
                $cmd = $this->pmssFindJsonStepCommand($jsonLog, 'Copying irssi skeleton config');
            });
        } finally {
            $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
        }

        $this->assertTrue(is_dir($home.'/.irssi'));
        $this->assertTrue($cmd === null || strpos($cmd, $skel.'/.irssi/config') !== false);
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/users/http.php',
            "pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/.irssi/config'");
    }
}

}
