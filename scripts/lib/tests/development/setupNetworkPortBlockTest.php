<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SetupNetworkPortBlockTest extends TestCase
{
    public function testSetupNetworkBlocksDefaultTorrentWebUiPortsOnPublicInterface(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $path = $repoRoot.'/scripts/util/setupNetwork.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(strpos($src, "-A INPUT -i ##IFACE## -p tcp --dport 8080 -j DROP") !== false);
        $this->assertTrue(strpos($src, "-A INPUT -i ##IFACE## -p tcp --dport 8112 -j DROP") !== false);
    }

    public function testSetupNetworkLimitsTcpsackLogging(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $path = $repoRoot.'/scripts/util/setupNetwork.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(strpos($src, '--log-prefix "tcpsack: "') !== false);
        $this->assertTrue(strpos($src, '-m limit --limit 2/second --limit-burst 10') !== false);
    }
}
