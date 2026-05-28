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
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/addUser.php',
            ['/scripts/startRtorrent', '/scripts/startLighttpd', '/scripts/util/setupNetwork.php'],
            'addUser.php missing service/network substring: ',
            'addUser.php service/network order changed near: '
        );
    }

    public function testAddUserRefreshesPatchedTorrentFrontendsBeforeServices(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/addUser.php', ["require_once 'lib/update.php';", "require_once 'lib/update/users.php';"]);
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/addUser.php',
            ['pmssUpdateUserEnvironment(', '/scripts/startLighttpd'],
            'addUser.php missing frontend refresh substring: ',
            'addUser.php must converge user environment before services start: '
        );
    }

    public function testAddUserDelegatesTrafficLimitPersistenceToSharedHelpers(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/addUser.php', [
            "require_once 'lib/user/trafficLimit.php';",
            'pmssTrafficLimitCliTargetModes($user[\'name\'], $homePath)',
            'pmssTrafficLimitPersistTargetModes($targetModes, (int) $user[\'trafficLimit\'], $persistError)',
        ]);
        $this->pmssAssertRepoFileNotContainsString('scripts/addUser.php', '@file_put_contents($runtimeDir', 'addUser.php must not reimplement runtime traffic limit writes');
        $this->pmssAssertRepoFileNotContainsString('scripts/addUser.php', '@file_put_contents("/home/{$user[\'name\']}/.trafficLimit"', 'addUser.php must not reimplement home traffic limit writes');
    }
}
