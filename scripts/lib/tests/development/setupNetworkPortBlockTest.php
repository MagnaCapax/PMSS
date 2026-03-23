<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SetupNetworkPortBlockTest extends TestCase
{
    public function testSetupNetworkBlocksDefaultTorrentWebUiPortsOnPublicInterface(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/util/setupNetwork.php',
            [
                "-A INPUT -i ##IFACE## -p tcp --dport 8080 -j DROP",
                "-A INPUT -i ##IFACE## -p tcp --dport 8112 -j DROP",
            ]
        );
    }

    public function testSetupNetworkLimitsTcpsackLogging(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/util/setupNetwork.php',
            ['--log-prefix "tcpsack: "', '-m limit --limit 2/second --limit-burst 10']
        );
    }

    public function testSetupNetworkLogsIpv4ForwardingWriteFailure(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/util/setupNetwork.php',
            [
                "@file_put_contents('/proc/sys/net/ipv4/ip_forward', '1') === false",
                "logMessage('setupNetwork: unable to enable IPv4 forwarding')",
            ]
        );
    }
}
