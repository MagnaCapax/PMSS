<?php
namespace PMSS\Tests {

/** The LinuxServer.io guide must tell tenants to check a default port before publishing it. */
class DocsLinuxserverPortCollisionTest extends TestCase
{
    private const GUIDE = 'docs/linuxserver.io.md';

    public function testPortsSectionWarnsAboutSharedHostCollisionsAndShowsTheCheck(): void
    {
        $guide = $this->pmssReadRepoFile(self::GUIDE);
        $ports = substr($guide, (int) strpos($guide, '### 2.3 Ports'), 3500);
        $this->assertStringContainsAndOmitsStrings([
            'shared with other accounts',
            '8096', '7878', '8989', '9696',
            "ss -tln | grep ':8096 '",
            'Choose another free',
            'HOST_PORT:CONTAINER_PORT',
        ], [], $ports);
    }

    public function testJellyfinWalkthroughChecksThePortBeforeTheRunCommand(): void
    {
        $guide = $this->pmssReadRepoFile(self::GUIDE);
        $step = (int) strpos($guide, '3. **Run Jellyfin**');
        $this->assertTrue($step > 0, 'Jellyfin run step is present');
        $check = strpos($guide, "ss -tln | grep ':8096 '", $step);
        $run = strpos($guide, 'docker run -d', $step);
        $this->assertTrue($check !== false && $run !== false && $check < $run, 'port check precedes docker run in the Jellyfin step');
    }
}

}
