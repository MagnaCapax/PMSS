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

        $ctx = [
            'user'     => 'dummy',
            'home'     => $home,
            'user_esc' => escapeshellarg('dummy'),
        ];

        \pmssUserConfigureHttp($ctx);

        $updated = file_get_contents($home.'/.config/qBittorrent/qBittorrent.conf');
        $updatedConfig = ($updated === false) ? '' : $updated;
        $this->assertStringContainsString('WebUI\\CSRFProtection=false', $updatedConfig);
        $this->assertStringContainsString('WebUI\\ClickjackingProtection=false', $updatedConfig);
        $this->assertStringContainsString('WebUI\\HostHeaderValidation=false', $updatedConfig);
        $this->assertTrue(strpos($updatedConfig, 'WebUI\\CSRFProtection=true') === false);
        $this->assertTrue(strpos($updatedConfig, 'WebUI\\ClickjackingProtection=true') === false);
        $this->assertTrue(strpos($updatedConfig, 'WebUI\\HostHeaderValidation=true') === false);
    }

    public function testConfigureHttpCreatesTempDirectory(): void
    {
        $tempHome = $this->pmssMakeTempDir('pmss-http-');
        mkdir($tempHome.'/.lighttpd', 0755, true);
        file_put_contents($tempHome.'/.lighttpd/php.ini', "display_errors = On\n");

        $ctx = [
            'user'     => 'dummy',
            'home'     => $tempHome,
            'user_esc' => escapeshellarg('dummy'),
        ];

        \pmssUserConfigureHttp($ctx);

        $ini = file_get_contents($tempHome.'/.lighttpd/php.ini');
        $this->assertTrue(strpos($ini, 'error_log') !== false);
    }

    public function testConfigureHttpRestoresQbittorrentManagedKeysWhenMissing(): void
    {
        $home = $this->pmssMakeTempDir('pmss-http-qbittorrent-missing-');
        @mkdir($home.'/.config/qBittorrent', 0755, true);

        $config = "[Preferences]\n";
        $config .= "WebUI\\Port=12345\n";
        $config .= "WebUI\\Address=*\n";
        file_put_contents($home.'/.config/qBittorrent/qBittorrent.conf', $config);

        $ctx = [
            'user'     => 'dummy',
            'home'     => $home,
            'user_esc' => escapeshellarg('dummy'),
        ];

        \pmssUserConfigureHttp($ctx);

        $updated = file_get_contents($home.'/.config/qBittorrent/qBittorrent.conf');
        $updatedConfig = ($updated === false) ? '' : $updated;
        $this->assertStringContainsString('WebUI\\Port=12345', $updatedConfig);
        $this->assertStringContainsString('WebUI\\Address=*', $updatedConfig);
        $this->assertStringContainsString('WebUI\\CSRFProtection=false', $updatedConfig);
        $this->assertStringContainsString('Downloads\\PreAllocation=false', $updatedConfig);
        $this->assertStringContainsString('Session\\DiskCacheSize=128', $updatedConfig);
    }

    public function testConfigureHttpUsesDefaultSkelPathForIrssiCopy(): void
    {
        $home = $this->pmssMakeTempDir('pmss-http-skel-default-');
        $ctx = [
            'user'     => 'dummy',
            'home'     => $home,
            'user_esc' => escapeshellarg('dummy'),
        ];

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

        $ctx = [
            'user'     => 'dummy',
            'home'     => $home,
            'user_esc' => escapeshellarg('dummy'),
        ];

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
