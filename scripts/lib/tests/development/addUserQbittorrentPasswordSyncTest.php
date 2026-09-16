<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class addUserQbittorrentPasswordSyncTest extends TestCase
{
    public function testProvisioningRequiresPasswordHelperLibrary(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/add/userConfigApply.php', "require_once __DIR__.'/../qbittorrent.php';");
    }

    public function testProvisioningSyncsQbittorrentPasswordAfterUserConfig(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/userConfigApply.php');

        $this->assertStringContainsString('pmssAddUserRunRequiredProvisionStep(', $source,
            'addUser provisioning must still use the required user config step helper');
        $this->assertOrderedStrings([
            "'Apply user configuration'",
            'pmssUpdateQbittorrentPassword($user[\'name\'], $user[\'password\'])',
        ], $source, '', 'qBittorrent password sync must happen after userConfig creates qBittorrent.conf: ');
    }

    public function testQbittorrentPasswordSyncStillRunsBeforeServiceStartup(): void
    {
        $applySource = $this->pmssReadRepoFile('scripts/lib/user/add/userConfigApply.php');
        $addUserSource = $this->pmssReadRepoFile('scripts/addUser.php');

        $syncPos = strpos($applySource, 'pmssUpdateQbittorrentPassword($user[\'name\'], $user[\'password\'])');
        $returnPos = strrpos($applySource, '}');

        $this->assertTrue($syncPos !== false, 'qBittorrent password sync hook must exist');
        $this->assertTrue($returnPos !== false, 'userConfigApply helper must remain readable');
        $this->assertTrue($syncPos < $returnPos, 'qBittorrent password sync must stay inside the user config phase');
        $this->assertOrderedStrings([
            'pmssAddUserUserConfigApply($userDb, $user, $homePath);',
            '/scripts/startRtorrent',
        ], $addUserSource, '', 'user config must finish before service startup: ');
    }

    public function testWelcomePageNoLongerAdvertisesLegacyAdminadminPassword(): void
    {
        $legacyPassword = 'admin'.'admin';

        $this->pmssAssertRepoFileNotContainsString('etc/skel/www/welcome.php', $legacyPassword, 'welcome page must not advertise the legacy qBittorrent password');
        $this->pmssAssertRepoFileContainsString('etc/skel/www/welcome.php', 'password matches your account password');
    }

    public function testQbittorrentFrontendRequestsPasswordOnlyWhenHashIsMissing(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('etc/skel/www/qbittorrent.php', [
            "\$action = pmssFrontendActionRequest();",
            'pmssQbittorrentFrontendPasswordSync($action);',
            'pmssFrontendToggleAction(',
            "isset(\$_POST['qbittorrentPassword'])",
            "http_response_code(428);",
            "'WebUI\\\\Password_PBKDF2'",
            'hash_pbkdf2(',
        ]);
        $this->pmssAssertRepoFileNotContainsString(
            'etc/skel/www/qbittorrent.php',
            "require_once '/scripts/",
            'customer qBittorrent endpoint must stay self-contained in the customer tree'
        );
    }

    public function testWelcomeRetriesQbittorrentStartWithPasswordPost(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('etc/skel/www/welcome.php', [
            'function pmssActionRequest(action, passwordValue)',
            'if (xhr.status === 428 && action.passwordField)',
            "window.prompt('Enter your account password to sync qBittorrent WebUI login.')",
            'pmssActionRequest(action, password).done',
            'if (action.passwordField && passwordValue !== undefined)',
            "request.type = 'POST';",
            'request.data[action.passwordField] = passwordValue;',
            'function pmssRunAction(button, url, successMessage, shouldReload, pendingMessage, passwordFieldName, passwordValue)',
            "url.indexOf('qbittorrent.php') === 0 ? 'qbittorrentPassword' : ''",
            'pmssActionRequest(action, passwordValue).done',
        ]);
    }
}
