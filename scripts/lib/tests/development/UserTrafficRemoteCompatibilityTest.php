<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserTrafficRemoteCompatibilityTest extends TestCase
{
    public function testRemoteUserTrafficUsesContractSafeUserEnumerationPath(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/util/remote/userTraffic.php',
            [
                'pmssManagedHomeUsersList()',
                'serialize($userTrafficData)',
            ],
            [
                "pmssListManagedUsers('/scripts/listUsers.php')" => 'Remote userTraffic callback must not rely on listUsers mount-gate bypass path',
                'PMSS_SKIP_HOME_MOUNT_CHECK=1' => 'Remote userTraffic callback must not rely on listUsers mount-gate bypass path',
            ]
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
                'Do not call /scripts/listUsers.php from this callback producer.',
            ],
            'Remote userTraffic script must keep callback payload format documented in-source'
        );
    }
}
