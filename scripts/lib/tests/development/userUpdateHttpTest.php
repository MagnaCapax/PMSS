<?php
namespace {
    pmssTestInstallRunUserStepShim();
}

namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserUpdateHttpTest extends TestCase
{
    public function testConfigureHttpDisablesQbittorrentReverseProxyChecks(): void
    {
        $home = $this->pmssMakeTempDir('pmss-http-qbittorrent-');
        mkdir($home.'/.config/qBittorrent', 0755, true);

        $config = "[Preferences]\n";
        $config .= "WebUI\\CSRFProtection=true\n";
        $config .= "WebUI\\ClickjackingProtection=true\n";
        $config .= "WebUI\\HostHeaderValidation=true\n";
        $config .= "WebUI\\Port=12345\n";
        file_put_contents($home.'/.config/qBittorrent/qBittorrent.conf', $config);

        $ctx = $this->pmssUserUpdateContext($home);

        \pmssUserConfigureHttp($ctx);

        $updated = file_get_contents($home.'/.config/qBittorrent/qBittorrent.conf');
        $updatedConfig = ($updated === false) ? '' : $updated;
        $this->assertStringContainsAllStrings(['WebUI\\CSRFProtection=false', 'WebUI\\ClickjackingProtection=false', 'WebUI\\HostHeaderValidation=false'], $updatedConfig);
        $this->assertTrue(strpos($updatedConfig, 'WebUI\\CSRFProtection=true') === false);
        $this->assertTrue(strpos($updatedConfig, 'WebUI\\ClickjackingProtection=true') === false);
        $this->assertTrue(strpos($updatedConfig, 'WebUI\\HostHeaderValidation=true') === false);
    }

    public function testConfigureHttpCreatesTempDirectory(): void
    {
        $tempHome = $this->pmssMakeTempDir('pmss-http-');
        mkdir($tempHome.'/.lighttpd', 0755, true);
        file_put_contents($tempHome.'/.lighttpd/php.ini', "display_errors = On\n");

        $ctx = $this->pmssUserUpdateContext($tempHome);

        \pmssUserConfigureHttp($ctx);

        $ini = file_get_contents($tempHome.'/.lighttpd/php.ini');
        $this->assertTrue(strpos($ini, 'error_log') !== false);
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
        $this->assertStringContainsAllStrings(['WebUI\\Port=12345', 'WebUI\\Address=*', 'WebUI\\CSRFProtection=false', 'Downloads\\PreAllocation=true', 'Session\\DiskCacheSize=128'], $updatedConfig);
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

        $previous = $this->pmssCaptureEnv(['PMSS_DRY_RUN', 'PMSS_JSON_LOG', 'PMSS_SKEL_DIR']);
        putenv('PMSS_DRY_RUN=1');
        putenv('PMSS_JSON_LOG='.$jsonLog);
        putenv('PMSS_SKEL_DIR');
        $GLOBALS['PMSS_JSON_LOG_PATH'] = null;

        $cmd = null;
        try {
            \pmssUserConfigureHttp($ctx);
            $cmd = $this->pmssFindJsonStepCommand($jsonLog, 'Copying irssi skeleton config');
        } finally {
            $this->pmssRestoreEnvMap($previous);
            $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
        }

        $expected = sprintf('cp /etc/skel/.irssi/config %s/', escapeshellarg($home.'/.irssi'));
        $this->assertEquals($expected, $cmd ?? '');
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

        $previous = $this->pmssCaptureEnv(['PMSS_DRY_RUN', 'PMSS_JSON_LOG', 'PMSS_SKEL_DIR']);
        putenv('PMSS_DRY_RUN=1');
        putenv('PMSS_JSON_LOG='.$jsonLog);
        putenv('PMSS_SKEL_DIR='.$skel);
        $GLOBALS['PMSS_JSON_LOG_PATH'] = null;

        $cmd = null;
        try {
            \pmssUserConfigureHttp($ctx);
            $cmd = $this->pmssFindJsonStepCommand($jsonLog, 'Copying irssi skeleton config');
        } finally {
            $this->pmssRestoreEnvMap($previous);
            $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
        }

        $expected = sprintf('cp %s %s/', escapeshellarg($skel.'/.irssi/config'), escapeshellarg($home.'/.irssi'));
        $this->assertEquals($expected, $cmd ?? '');
    }
}

}
