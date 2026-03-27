<?php
namespace PMSS\Tests;

// Sanity tests for scripts/lib/networkInfo.php helpers
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/networkInfo.php';

class NetworkInfoTest extends TestCase
{
    public function testDetectPrimaryInterfaceReturnsString(): void
    {
        $iface = \detectPrimaryInterface();
        $this->assertTrue(is_string($iface));
        $this->assertTrue($iface !== '');
    }

    public function testGetLinkSpeedReturnsInt(): void
    {
        $iface = \detectPrimaryInterface();
        $speed = \getLinkSpeed($iface);
        $this->assertTrue(is_int($speed));
        $this->assertTrue($speed > 0);
    }

    public function testDetectPrimaryInterfaceUsesSharedConfigOverride(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-network-info-', '.php');
        file_put_contents($tmp, "<?php return ['interface' => 'bond9'];");

        $this->pmssWithEnv(['PMSS_NETWORK_CONFIG' => $tmp], function (): void {
            $this->assertSame('bond9', \detectPrimaryInterface());
        });
    }

    public function testGetLinkSpeedUsesSharedConfigOverride(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-network-info-', '.php');
        file_put_contents($tmp, "<?php return ['speed' => '4321'];");

        $this->pmssWithEnv(['PMSS_NETWORK_CONFIG' => $tmp], function (): void {
            $this->assertSame(4321, \getLinkSpeed('eth0'));
        });
    }
}
