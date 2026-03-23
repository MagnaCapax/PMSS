<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RootCronSystemdServicesGuardTest extends TestCase
{
    public function testRootCronIncludesSystemdServicesGuard(): void
    {
        $path = 'etc/seedbox/config/root.cron';
        $this->pmssAssertRepoFileContainsAllStrings(
            $path,
            ['MAILTO=""', '/scripts/cron/systemdServicesGuard.php', '/var/log/pmss/systemdServicesGuard.log'],
            'root.cron should include: '
        );
        $this->pmssAssertRepoFileSubstringCount($path, '/scripts/cron/systemdServicesGuard.php', 2, 'Expected guard to run @reboot and periodically');
    }
}
