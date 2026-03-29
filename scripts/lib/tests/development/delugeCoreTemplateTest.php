<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/deluge.php';

class DelugeCoreTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PMSS_SEEDBOX_CONFIG_DIR='.dirname(__DIR__, 4).'/etc/seedbox/config');
    }

    protected function tearDown(): void
    {
        putenv('PMSS_SEEDBOX_CONFIG_DIR');
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
}
