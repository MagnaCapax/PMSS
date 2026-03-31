<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserTrafficRemoteCompatibilityTest extends TestCase
{
    public function testRemoteUserTrafficBypassesHomeMountGateForLegacyCallbacks(): void
    {
        $path = 'scripts/util/remote/userTraffic.php';
        $this->pmssAssertRepoFileContainsAllStrings(
            $path,
            [
                "PMSS_SKIP_HOME_MOUNT_CHECK=1",
                "pmssListManagedUsers('/scripts/listUsers.php')",
                'serialize($userTrafficData)',
            ],
            'Remote userTraffic callback must stay mount-gate compatible for legacy Hallinta consumers'
        );
    }
}
