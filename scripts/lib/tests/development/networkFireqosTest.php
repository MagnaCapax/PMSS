<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/network/fireqos.php';

class NetworkFireqosTest extends TestCase
{
    public function testBuildFireqosConfigRendersPlaceholders(): void
    {
        $template = "iface ##INTERFACE\nrate ##SPEED\n##LOCALNETWORK\n##USERMATCHES\n";
        $path = sys_get_temp_dir().'/fireqos-template-'.bin2hex(random_bytes(4)).'.conf';
        file_put_contents($path, $template);
        putenv('PMSS_FIREQOS_TEMPLATE='.$path);

        try {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth1', 'speed' => 500, 'throttle' => ['max' => 100]],
                [],
                ['10.0.0.0/8']
            );
            $this->assertTrue(strpos($config, 'eth1') !== false);
            $this->assertTrue(strpos($config, '500') !== false);
            $this->assertTrue(strpos($config, 'match dst 10.0.0.0/8') !== false);
        } finally {
            @unlink($path);
            putenv('PMSS_FIREQOS_TEMPLATE');
        }
    }
}
