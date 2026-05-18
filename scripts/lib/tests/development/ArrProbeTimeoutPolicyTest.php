<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/arr.php';

class ArrProbeTimeoutPolicyTest extends TestCase
{
    public function testVersionProbeCommandKeepsProwlarrProbeBounded(): void
    {
        $command = \pmssArrVersionProbeCommand('/opt/Prowlarr/Prowlarr', '--version');

        $this->assertStringContainsAllStrings([
            'timeout --kill-after=5s 50s ',
            "'/opt/Prowlarr/Prowlarr'",
            "'--version'",
            '2>/dev/null',
        ], $command);
        $legacyTimeout = 'timeout '.'10 ';
        $this->assertStringNotContainsString($legacyTimeout, $command);
    }

    public function testSupportedStarrAppsUseSharedInstallPathPreset(): void
    {
        foreach (['Lidarr', 'Prowlarr', 'Radarr', 'Readarr', 'Sonarr'] as $app) {
            $config = \pmssArrAppConfig($app);

            $this->assertTrue(is_array($config), $app.' config should exist');
            $this->assertSame('/opt/'.$app, (string) $config['install_path']);
        }
    }
}
