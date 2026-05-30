<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserTrafficRemoteCompatibilityTest extends TestCase
{
    public function testRemoteUserTrafficKeepsCallbackSafeContract(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/util/remote/userTraffic.php',
            [
                'pmssManagedHomeUsersList()',
                'serialize($userTrafficData)',
                'Output contract (STDOUT only, no banners/warnings):',
                'array<string,array{normal:int,local:int,ingress:int}>',
                'Hallinta callback path expects unserialize()-compatible payload',
                'Any extra STDOUT bytes can break callback parsing',
                'Do not call /scripts/listUsers.php from this callback producer.',
            ],
            [
                "pmssListManagedUsers('/scripts/listUsers.php')" => 'Remote userTraffic callback must not rely on listUsers mount-gate bypass path',
                'PMSS_SKIP_HOME_MOUNT_CHECK=1' => 'Remote userTraffic callback must not rely on listUsers mount-gate bypass path',
            ]
        );
    }
}
