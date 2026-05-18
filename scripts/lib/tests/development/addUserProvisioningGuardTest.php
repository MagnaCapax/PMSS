<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/add/provisioningRuntime.php';

class AddUserProvisioningGuardTest extends TestCase
{
    public function testAddUserUsesPerUserLock(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/addUser.php', ['pmss-addUser-', 'pmssLockFileAcquire('], 'addUser.php missing lock guard: ');
    }

    public function testAddUserEmitsSummaryMarker(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/add/provisioningRuntime.php', ['###ADDUSER:', '###ADDUSER_JSON:'], 'addUser summary marker missing: ');
    }

    public function testAddUserRuntimeInitStaysWithProvisioningRuntimeHelpers(): void
    {
        $this->assertTrue(function_exists('\pmssAddUserRuntimeInit'));
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/add/provisioningRuntime.php', 'function pmssAddUserRuntimeInit(');
    }

    public function testAddUserWrapperStaysSmall(): void
    {
        $lines = file($this->pmssRepoPath('scripts/addUser.php'), FILE_IGNORE_NEW_LINES);
        $this->assertTrue(is_array($lines), 'addUser.php must be readable');
        $this->assertTrue(count($lines) <= 200, 'addUser.php must stay under 200 lines');
    }

    public function testAddUserStillStartsServicesAndRefreshesNetwork(): void
    {
        $src = $this->pmssReadRepoFile('scripts/addUser.php');
        $rtorrentPos = strpos($src, '/scripts/startRtorrent');
        $lighttpdPos = strpos($src, '/scripts/startLighttpd');
        $networkPos = strpos($src, '/scripts/util/setupNetwork.php');

        $this->assertTrue($rtorrentPos !== false, 'addUser.php must start rTorrent');
        $this->assertTrue($lighttpdPos !== false, 'addUser.php must start lighttpd');
        $this->assertTrue($networkPos !== false, 'addUser.php must refresh network rules');
        $this->assertTrue($rtorrentPos < $lighttpdPos, 'addUser.php must start rTorrent before lighttpd');
        $this->assertTrue($lighttpdPos < $networkPos, 'addUser.php must refresh network after starting services');
    }

    public function testAddUserRefreshesPatchedTorrentFrontendsBeforeServices(): void
    {
        $src = $this->pmssReadRepoFile('scripts/addUser.php');
        $patchPos = strpos($src, "pmssUpdateUserEnvironment(");
        $lighttpdPos = strpos($src, '/scripts/startLighttpd');

        $this->assertStringContainsAllStrings(["require_once 'lib/update.php';", "require_once 'lib/update/users.php';"], $src);
        $this->assertTrue($patchPos !== false, 'addUser.php must converge the full user environment after user config');
        $this->assertTrue($lighttpdPos !== false, 'addUser.php must still start lighttpd');
        $this->assertTrue($patchPos < $lighttpdPos, 'addUser.php must converge user environment before services start');
    }

    public function testAddUserDelegatesTrafficLimitPersistenceToSharedHelpers(): void
    {
        $src = $this->pmssReadRepoFile('scripts/addUser.php');

        $this->assertStringContainsAllStrings([
            "require_once 'lib/user/trafficLimit.php';",
            'pmssTrafficLimitCliTargetModes($user[\'name\'], $homePath)',
            'pmssTrafficLimitPersistTargetModes($targetModes, (int) $user[\'trafficLimit\'], $persistError)',
        ], $src);
        $this->pmssAssertStringNotContainsString('@file_put_contents($runtimeDir', $src, 'addUser.php must not reimplement runtime traffic limit writes');
        $this->pmssAssertStringNotContainsString('@file_put_contents("/home/{$user[\'name\']}/.trafficLimit"', $src, 'addUser.php must not reimplement home traffic limit writes');
    }
}
