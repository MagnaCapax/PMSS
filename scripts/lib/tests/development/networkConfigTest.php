<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/network/config.php';

class NetworkConfigTest extends TestCase
{
    public function testLoadConfigUsesOverride(): void
    {
        $tmp = sys_get_temp_dir().'/pmss-network-config-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($tmp, "<?php return ['interface' => 'eth9'];");
        putenv('PMSS_NETWORK_CONFIG='.$tmp);
        try {
            $config = \networkLoadConfig();
            $this->assertEquals('eth9', $config['interface']);
        } finally {
            @unlink($tmp);
            putenv('PMSS_NETWORK_CONFIG');
        }
    }

    public function testLoadLocalnetsCreatesDefault(): void
    {
        $tmp = sys_get_temp_dir().'/pmss-localnets-'.bin2hex(random_bytes(4));
        putenv('PMSS_LOCALNET_FILE='.$tmp);
        try {
            $nets = \networkLoadLocalnets();
            $this->assertEquals(['185.148.0.0/22'], $nets);
            $this->assertTrue(file_exists($tmp));
        } finally {
            @unlink($tmp);
            putenv('PMSS_LOCALNET_FILE');
        }
    }
}
