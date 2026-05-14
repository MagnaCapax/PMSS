<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/deluge.php';

class DelugeCoreTemplateTest extends TestCase
{
    private $homeRoot = '';
    private $configRoot = '';

    protected function setUp(): void
    {
        $this->pmssTrackEnvOverrides([
            'PMSS_SEEDBOX_CONFIG_DIR' => dirname(__DIR__, 4).'/etc/seedbox/config',
            'PMSS_HOME_DIR' => null,
            'PMSS_DRY_RUN' => null,
            'PMSS_DELUGE_AUTH_TEMPLATE_PATH' => null,
        ]);
    }

    public function testBullseyeTemplateKeepsCacheSettings(): void
    {
        $config = \pmssDelugeRenderCoreConfig(
            ['name' => 'alice', 'memory' => 4],
            6000,
            '123.0',
            ['version' => 11, 'codename' => 'bullseye']
        );

        $this->assertStringContainsString('"cache_expiry": 90', $config);
        $this->assertStringContainsString('"cache_size": 256', $config);
        $this->assertStringContainsString('"daemon_port": 6000', $config);
        $this->assertStringContainsString('"max_upload_speed": 123.0', $config);
    }

    public function testBookwormTemplateOmitsLegacyCacheSettings(): void
    {
        $config = \pmssDelugeRenderCoreConfig(
            ['name' => 'alice', 'memory' => 4],
            6000,
            '-1.0',
            ['version' => 12, 'codename' => 'bookworm']
        );

        $this->pmssAssertStringNotContainsString('"cache_expiry"', $config);
        $this->pmssAssertStringNotContainsString('"cache_size"', $config);
        $this->assertStringContainsString('"max_active_seeding": 300', $config);
        $this->assertStringContainsString('"dht": false', $config);
        $this->assertStringContainsString('"lsd": false', $config);
        $this->assertStringContainsString('"upnp": false', $config);
    }

    public function testTrixieUsesNoCacheTemplate(): void
    {
        $path = \pmssDelugeCoreTemplatePath(['version' => 13, 'codename' => 'trixie']);

        $this->assertStringContainsString('template.deluge.core.nocache.conf', $path);
    }

    public function testAuxiliaryTemplatePathUsesConfiguredConfigRoot(): void
    {
        $this->pmssAssignTempDirProperty('configRoot', 'pmss-deluge-config-root-');
        putenv('PMSS_SEEDBOX_CONFIG_DIR='.$this->configRoot);

        $path = \pmssDelugeTemplatePath('template.deluge.web.conf');

        $this->assertSame($this->configRoot.'/template.deluge.web.conf', $path);
    }

    public function testUserConfigureDelugeSkipsWritesWhenRequiredTemplateMissing(): void
    {
        $user = ['name' => 'alice', 'memory' => 4];
        $this->pmssAssignTempDirProperty('homeRoot', 'pmss-deluge-home-root-');
        $this->pmssAssignTempDirProperty('configRoot', 'pmss-deluge-config-root-');
        putenv('PMSS_HOME_DIR='.$this->homeRoot);
        putenv('PMSS_SEEDBOX_CONFIG_DIR='.$this->configRoot);
        putenv('PMSS_DRY_RUN=1');

        $configDir = $this->homeRoot.'/alice/.config/deluge';
        @mkdir($configDir, 0755, true);
        @mkdir($this->homeRoot.'/alice/dataUnfinished', 0755, true);
        @mkdir($this->homeRoot.'/alice/.sessionDeluge', 0755, true);
        file_put_contents($configDir.'/core.conf', 'original core');
        file_put_contents($configDir.'/hostlist.conf', 'original hostlist');
        file_put_contents($configDir.'/web.conf', 'original web');
        file_put_contents($this->homeRoot.'/alice/.delugePort', '6111');

        file_put_contents($this->configRoot.'/template.deluge.core.conf', '"daemon_port": ##DAEMONPORT');
        file_put_contents($this->configRoot.'/template.deluge.core.nocache.conf', '"daemon_port": ##DAEMONPORT');
        file_put_contents($this->configRoot.'/template.deluge.hostlist.conf', 'daemon=##DAEMONPORT');

        \userConfigureDeluge($user, ['config' => ['scgiPort' => 6200]]);

        $this->assertSame('original core', (string) file_get_contents($configDir.'/core.conf'));
        $this->assertSame('original hostlist', (string) file_get_contents($configDir.'/hostlist.conf'));
        $this->assertSame('original web', (string) file_get_contents($configDir.'/web.conf'));
        $this->assertSame('6111', trim((string) file_get_contents($this->homeRoot.'/alice/.delugePort')));
    }

    public function testUserConfigureDelugeUsesConfiguredTemplateRootForWrites(): void
    {
        $user = ['name' => 'alice', 'memory' => 4];
        $this->pmssAssignTempDirProperty('homeRoot', 'pmss-deluge-home-root-');
        $this->pmssAssignTempDirProperty('configRoot', 'pmss-deluge-config-root-');
        putenv('PMSS_HOME_DIR='.$this->homeRoot);
        putenv('PMSS_SEEDBOX_CONFIG_DIR='.$this->configRoot);
        putenv('PMSS_DELUGE_AUTH_TEMPLATE_PATH='.$this->configRoot.'/template.deluge.auth');
        putenv('PMSS_DRY_RUN=1');

        $configDir = $this->homeRoot.'/alice/.config/deluge';
        @mkdir($configDir, 0755, true);
        @mkdir($this->homeRoot.'/alice/dataUnfinished', 0755, true);
        @mkdir($this->homeRoot.'/alice/.sessionDeluge', 0755, true);
        file_put_contents($configDir.'/auth', "localclient:seed:10\n");
        file_put_contents($this->configRoot.'/template.deluge.core.conf', '"daemon_port": ##DAEMONPORT, "max_upload_speed": ##UPLOAD_THROTTLE##');
        file_put_contents($this->configRoot.'/template.deluge.core.nocache.conf', '"daemon_port": ##DAEMONPORT, "max_upload_speed": ##UPLOAD_THROTTLE##');
        file_put_contents($this->configRoot.'/template.deluge.hostlist.conf', 'daemon=##DAEMONPORT');
        file_put_contents($this->configRoot.'/template.deluge.web.conf', 'user=##USER port=##WEBPORT');
        file_put_contents($this->configRoot.'/template.deluge.auth', "localclient:seed:10\n");

        \userConfigureDeluge($user, ['config' => ['scgiPort' => 6200]]);

        $this->assertStringContainsString('"daemon_port": 6200', (string) file_get_contents($configDir.'/core.conf'));
        $this->assertSame('daemon=6200', (string) file_get_contents($configDir.'/hostlist.conf'));
        $this->assertSame('user=alice port=6201', (string) file_get_contents($configDir.'/web.conf'));
        $this->assertSame('6200', trim((string) file_get_contents($this->homeRoot.'/alice/.delugePort')));
    }
}
