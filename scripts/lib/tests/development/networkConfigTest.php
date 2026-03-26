<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/network/config.php';

class NetworkConfigTest extends TestCase
{
    public function testLoadConfigUsesOverride(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-network-config-', '.php');
        file_put_contents($tmp, "<?php return ['interface' => 'eth9'];");

        $this->pmssWithEnv(['PMSS_NETWORK_CONFIG' => $tmp], function (): void {
            $config = \networkLoadConfig();
            $this->assertEquals('eth9', $config['interface']);
        });
    }

    public function testLoadLocalnetsCreatesDefault(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-localnets-');

        $this->pmssWithEnv([
            'PMSS_LOCALNET_FILE' => $tmp,
            'PMSS_HOSTNAME' => 'seedbox1.pulsedmedia.com',
        ], function () use ($tmp): void {
            $nets = \networkLoadLocalnets();
            $this->assertEquals(['185.148.0.0/22'], $nets);
            $this->assertTrue(file_exists($tmp));
        });
    }

    public function testLoadLocalnetsKeepsNonPmHostsEmptyWhenConfigMissing(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-localnets-');

        $this->pmssWithEnv([
            'PMSS_LOCALNET_FILE' => $tmp,
            'PMSS_HOSTNAME' => 'seedbox1.example.com',
        ], function () use ($tmp): void {
            $this->assertEquals([], \networkLoadLocalnets());
            $this->assertFalse(file_exists($tmp));
        });
    }

    public function testLoadLocalnetsReturnsConfiguredEntries(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-localnets-');
        file_put_contents($tmp, "10.0.0.0/8\n192.168.0.0/16\n");

        $this->pmssWithEnv(['PMSS_LOCALNET_FILE' => $tmp], function (): void {
            $this->assertEquals(['10.0.0.0/8', '192.168.0.0/16'], \networkLoadLocalnets());
        });
    }

    public function testLoadLocalnetsReturnsEmptyWhenConfigFileIsBlank(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-localnets-');
        file_put_contents($tmp, "\n");

        $this->pmssWithEnv(['PMSS_LOCALNET_FILE' => $tmp], function (): void {
            $this->assertEquals([], \networkLoadLocalnets());
        });
    }
}
