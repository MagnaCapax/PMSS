<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SetupNetworkPortBlockTest extends TestCase
{
    public function testSetupNetworkKeepsPortBlockAndSafetyContracts(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/setupNetwork.php', [
            'required' => [
                "-A INPUT -i ##IFACE## -p tcp --dport 8080 -j DROP",
                "-A INPUT -i ##IFACE## -p tcp --dport 8112 -j DROP",
                '--log-prefix "tcpsack: "',
                '-m limit --limit 2/second --limit-burst 10',
                "@file_put_contents('/proc/sys/net/ipv4/ip_forward', '1') === false",
                "logMessage('setupNetwork: unable to enable IPv4 forwarding')",
                "networkInterfaceNameNormalized(\$configuredInterface)",
                "logMessage('setupNetwork: unsafe configured interface name')",
                "die(\"Error: Unsafe configured network interface\\n\")",
                "networkInterfaceNameNormalized(\$matches[1])",
            ],
        ]);
    }
}
