<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class addUserQbittorrentPasswordSyncTest extends TestCase
{
    public function testProvisioningRequiresPasswordHelperLibrary(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/userConfigApply.php');

        $this->assertStringContainsString("require_once __DIR__.'/../passwords.php';", $source);
    }

    public function testProvisioningSyncsQbittorrentPasswordAfterUserConfig(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/userConfigApply.php');

        $configPos = strpos($source, 'pmssAddUserRunRequiredProvisionStep(');
        $configStepPos = strpos($source, "'Apply user configuration'");
        $syncPos = strpos($source, 'pmssUpdateQbittorrentPassword($user[\'name\'], $user[\'password\'])');

        $this->assertTrue($configPos !== false, 'addUser provisioning must still use the required user config step helper');
        $this->assertTrue($configStepPos !== false, 'addUser provisioning must still apply the user config');
        $this->assertTrue($syncPos !== false, 'addUser provisioning must sync qBittorrent password hashes');
        $this->assertTrue($configStepPos < $syncPos, 'qBittorrent password sync must happen after userConfig creates qBittorrent.conf');
    }

    public function testQbittorrentPasswordSyncStillRunsBeforeServiceStartup(): void
    {
        $applySource = $this->pmssReadRepoFile('scripts/lib/user/add/userConfigApply.php');
        $addUserSource = $this->pmssReadRepoFile('scripts/addUser.php');

        $syncPos = strpos($applySource, 'pmssUpdateQbittorrentPassword($user[\'name\'], $user[\'password\'])');
        $returnPos = strrpos($applySource, '}');
        $configCallPos = strpos($addUserSource, 'pmssAddUserUserConfigApply($userDb, $user, $homePath);');
        $startPos = strpos($addUserSource, '/scripts/startRtorrent');

        $this->assertTrue($syncPos !== false, 'qBittorrent password sync hook must exist');
        $this->assertTrue($returnPos !== false, 'userConfigApply helper must remain readable');
        $this->assertTrue($configCallPos !== false, 'addUser.php must still call the user config helper');
        $this->assertTrue($startPos !== false, 'addUser.php must still start per-user services');
        $this->assertTrue($syncPos < $returnPos, 'qBittorrent password sync must stay inside the user config phase');
        $this->assertTrue($configCallPos < $startPos, 'user config must finish before service startup');
    }

    public function testWelcomePageNoLongerAdvertisesLegacyAdminadminPassword(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $legacyPassword = 'admin'.'admin';

        $this->assertTrue(strpos($source, $legacyPassword) === false, 'welcome page must not advertise the legacy qBittorrent password');
        $this->assertStringContainsString('password matches your account password', $source);
    }
}
