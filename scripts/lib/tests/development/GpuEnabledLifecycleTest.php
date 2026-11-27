<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/renderNodeAccess.php';

/** Account lifecycle hooks must respect the operator's GPU policy before group changes. */
class GpuEnabledLifecycleTest extends TestCase
{
    public function testDisabledAccountsAreSkippedEvenWhenAlreadyRenderMembers(): void
    {
        $commands = $messages = [];
        $ops = self::pmssMakeCallbacks(['intel' => true, 'group' => ['members' => ['fixtur01']],
            'users' => ['fixtur01', 'fixtur02', 'fixtur03'], 'off' => false, 'path' => '/usr/bin/gpasswd']);
        $runner = $this->pmssMakeStepRunner($commands);
        $logger = $this->pmssMakeArrayLogger($messages);
        $this->assertSame('skipped', \pmssEnsureIntelRenderGroupMember('fixtur01', $logger, $ops['intel'], $ops['group'], $runner, $ops['path'], $ops['off']));
        $this->assertSame('skipped', \pmssEnsureIntelRenderGroupMember('fixtur02', $logger, $ops['intel'], $ops['group'], $runner, $ops['path'], $ops['off']));
        $this->assertSame([], $commands);
        $enabled = static function ($user): bool { return $user === 'fixtur03'; };
        \pmssEnsureIntelRenderGroupMembers($logger, $ops['intel'], $ops['group'], $ops['users'], $runner, $ops['path'], $enabled);
        $this->assertSame([['Adding account to render group', "'/usr/bin/gpasswd' -a 'fixtur03' render"]], $commands);
        $this->assertTrue(in_array('Intel render group: 1 added, 0 already members', $messages, true));
    }

    public function testCreationUsesParsedGpuPolicyBeforeTheStoreExists(): void
    {
        // Stop at the next account observation, before any home, password or service work.
        $script = <<<'PHP'
namespace GpuCreationFixture;
function runProvisionStep($label, $command) { $GLOBALS['steps'][] = $label; return 0; }
function pmssEnsureIntelRenderGroupMember($name, $logger) { $GLOBALS['steps'][] = 'render'; }
function pmssUserAccountLookup($name) { throw new \RuntimeException('account boundary'); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/add/systemUserCreate.php', 'GpuCreationFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([[], ['gpuEnabled' => true], ['gpuEnabled' => false], ['gpuEnabled' => 'false'], ['gpuEnabled' => 'true']] as $policy) {
    $GLOBALS['steps'] = [];
    try {
        pmssAddUserSystemUserCreate(array_merge(['name' => 'fixtur01'], $policy), '/fixture/home');
    } catch (\RuntimeException $error) {
        if ($error->getMessage() !== 'account boundary') throw $error;
    }
    $results[] = $GLOBALS['steps'];
}
echo json_encode($results);
PHP;
        $this->assertSame([
            ['Create system user', 'render'], ['Create system user', 'render'],
            ['Create system user'], ['Create system user'], ['Create system user', 'render'],
        ], $this->pmssRunInlinePhpJson($script));
    }

    public function testTransferUsesLocalStoreAndCreationConvergesBeforeServices(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/userTransfer.php', [
            '$transferStore = new UserConfigStore()', "if (\$cfg['dryRun'])", "if (pmssUserGpuEnabled(\$cfg['localUser'], \$transferStore)) {",
            "pmssEnsureIntelRenderGroupMember(\$cfg['localUser'], 'logMessage')",
        ]);
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/user/add/userConfigApply.php', [
            "\$payload['gpuEnabled'] = \$user['gpuEnabled']", "\$userDb->addUser(\$user['name'], \$payload)",
            "pmssEnsureIntelRenderNodeDenies('logProvisionMessage')", "'Assign lighttpd port'",
        ]);
    }

    public function testTransferSkipsRenderForTheDisabledLocalAccount(): void
    {
        // Stop before password resolution or scratch creation; intercept even log-directory probes.
        $script = <<<'PHP'
namespace GpuTransferFixture;
require_once REPO_PATH.'/scripts/lib/user/userConfigStore.php';
class UserConfigStore extends \UserConfigStore {
    public function __construct() {}
    public function get(string $name): ?array { return ['gpuEnabled' => $GLOBALS['enabled']]; }
}
class RuntimeException extends \RuntimeException {}
function posix_geteuid() { return 0; }
function is_dir($path) { return true; }
function putenv($entry) { return true; }
function logMessage($message) {}
function pmssUserTransferParseCli($argv) { return ['localUser' => 'fixtur01', 'remoteUser' => 'fixtur02',
    'hostname' => 'fixture.invalid', 'dryRun' => false, 'suffixAppended' => false,
    'mainPasses' => 1, 'finalPasses' => 1, 'sleepMin' => 0, 'sleepMax' => 0, 'verifyThreshold' => 99]; }
function pmssUserTransferAssertSafeLocalHome($name) { return '/fixture/home'; }
function pmssUserTransferAssertNotRepeated($cfg, $store) {}
function pmssEnsureIntelRenderGroupMember($name, $logger) { $GLOBALS['members'][] = $name; }
function pmssUserTransferResolvePassword() { throw new RuntimeException('password boundary', 1); }
function pmssCliReturnWithStderr($message, $rc) { return $rc; }
PHP;
        $script = str_replace('REPO_PATH', var_export($this->pmssRepoPath(''), true), $script);
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/userTransfer.php', 'GpuTransferFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([false, true] as $enabled) {
    $GLOBALS['enabled'] = $enabled;
    $GLOBALS['members'] = [];
    $rc = pmssUserTransferMain(['userTransfer.php']);
    $results[] = [$rc, $GLOBALS['members']];
}
echo json_encode($results);
PHP;
        $this->assertSame([[1, []], [1, ['fixtur01']]], $this->pmssRunInlinePhpJson($script));
    }

    public function testTerminationAndUpdateConvergeInTheRequiredOrder(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/terminateUser.php', [
            'if (pmssUserAccountLookup($username) !== null)', '} elseif ($dryRun)', '$db->removeUser($username)',
            "pmssEnsureIntelRenderNodeDenies('logMessage')",
        ]);
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/util/update-step2.php', [
            "'pmssEnsureIntelRenderNodeAccess'", "'pmssEnsureIntelRenderGroupMembers'", "'pmssEnsureIntelRenderNodeDenies'",
        ]);
    }
}
