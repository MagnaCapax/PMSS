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

    public function testRemoteUserTrafficDocumentsSerializedCallbackContract(): void
    {
        $path = 'scripts/util/remote/userTraffic.php';
        $this->pmssAssertRepoFileContainsAllStrings(
            $path,
            [
                'Output contract (STDOUT only, no banners/warnings):',
                'array<string,array{normal:int,local:int,ingress:int}>',
                'Hallinta callback path expects unserialize()-compatible payload',
                'Any extra STDOUT bytes can break callback parsing',
            ],
            'Remote userTraffic script must keep callback payload format documented in-source'
        );
    }
}
