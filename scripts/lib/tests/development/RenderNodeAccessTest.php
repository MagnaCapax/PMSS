<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

/** Managed udev access must converge without repeated device triggers. */
class RenderNodeAccessTest extends TestCase
{
    public function testRuleIsExactAndReloadRunsOnlyAfterChange(): void
    {
        $root = $this->pmssMakeTempDir('pmss-render-rule-', 0700);
        $target = $root.'/70-pmss-render-node.rules';
        $messages = [];
        $commands = [];
        $logger = $this->pmssMakeArrayLogger($messages);
        $runner = $this->pmssMakeStepRunner($commands);
        $path = self::pmssMakeCallbacks(['path' => '/usr/bin/udevadm'])['path'];

        \pmssEnsureIntelRenderNodeAccess($logger, $target, $runner, $path);
        $this->assertSame(
            "# PMSS-managed: allow all local accounts to use Intel render nodes.\n"
                ."SUBSYSTEM==\"drm\", KERNEL==\"renderD*\", DRIVERS==\"i915|xe\", MODE=\"0666\"\n",
            file_get_contents($target)
        );
        $this->assertSame(0644, fileperms($target) & 0777);
        $this->assertSame([
            ['Reloading udev rules for Intel render nodes', "'/usr/bin/udevadm' control --reload"],
            ['Applying Intel render-node access', "'/usr/bin/udevadm' trigger --subsystem-match=drm --action=change"],
        ], $commands);

        \pmssEnsureIntelRenderNodeAccess($logger, $target, $runner, $path);
        $this->assertSame(2, count($commands));
        $this->assertTrue(in_array('[SKIP] Intel render-node access rule already present and up to date', $messages, true));
    }

    public function testMissingUdevadmWarnsWithoutRunningCommands(): void
    {
        $root = $this->pmssMakeTempDir('pmss-render-missing-udev-', 0700);
        $messages = [];
        $commands = [];
        $logger = $this->pmssMakeArrayLogger($messages);
        $runner = static function (string $description, string $command) use (&$commands): int { $commands[] = $command; return 0; };
        $path = self::pmssMakeCallbacks(['path' => ''])['path'];
        \pmssEnsureIntelRenderNodeAccess($logger, $root.'/rule', $runner, $path);
        $this->assertSame([], $commands);
        $this->assertTrue(in_array('[WARN] udevadm unavailable; Intel render-node rule will apply on the next device event', $messages, true));
    }

    public function testNonIntelAndMissingGroupSkipAccountScan(): void
    {
        $commands = [];
        $listCalls = 0;
        $runner = static function ($label, $command) use (&$commands): int { $commands[] = $command; return 0; };
        $list = static function () use (&$listCalls): array { $listCalls++; return ['alice']; };
        $ops = self::pmssMakeCallbacks(['present' => true, 'absent' => false, 'group' => ['members' => []]]);
        \pmssEnsureIntelRenderGroupMembers(null, $ops['absent'], $ops['group'], $list, $runner);
        \pmssEnsureIntelRenderGroupMembers(null, $ops['present'], $ops['absent'], $list, $runner);
        $this->assertSame(0, $listCalls);
        $this->assertSame([], $commands);
    }

    public function testConvergenceAddsOnlyValidNonmembersAndSecondRunIsIdle(): void
    {
        $commands = [];
        $messages = [];
        $members = ['alice'];
        $logger = $this->pmssMakeArrayLogger($messages);
        $group = static function () use (&$members): array { return ['members' => $members]; };
        $ops = self::pmssMakeCallbacks(['users' => ['alice', 'bob', 'bad-name', 'carol'], 'intel' => true]);
        $runner = static function ($label, $command) use (&$commands, &$members): int {
            $commands[] = $command;
            $members[] = explode("'", $command)[3];
            return 0;
        };
        $path = static function (string $binary): string { return $binary === 'gpasswd' ? '/usr/bin/gpasswd' : ''; };
        \pmssEnsureIntelRenderGroupMembers($logger, $ops['intel'], $group, $ops['users'], $runner, $path);
        $this->assertSame(["'/usr/bin/gpasswd' -a 'bob' render", "'/usr/bin/gpasswd' -a 'carol' render"], $commands);
        $this->assertTrue(in_array('Intel render group: 2 added, 1 already members', $messages, true));
        $commands = [];
        \pmssEnsureIntelRenderGroupMembers($logger, $ops['intel'], $group, $ops['users'], $runner, $path);
        $this->assertSame([], $commands);
        $this->assertTrue(in_array('Intel render group: 0 added, 3 already members', $messages, true));
    }

    public function testNewAccountUsesSameHardwareAndGroupGate(): void
    {
        $commands = [];
        $runner = static function ($label, $command) use (&$commands): int { $commands[] = $command; return 0; };
        $ops = self::pmssMakeCallbacks(['path' => '/usr/bin/gpasswd', 'present' => true, 'absent' => false, 'group' => ['members' => []]]);
        $this->assertSame('skipped', \pmssEnsureIntelRenderGroupMember('bob', null, $ops['absent'], $ops['group'], $runner, $ops['path']));
        $this->assertSame('skipped', \pmssEnsureIntelRenderGroupMember('bob', null, $ops['present'], $ops['absent'], $runner, $ops['path']));
        $this->assertSame([], $commands);
        $this->assertSame('added', \pmssEnsureIntelRenderGroupMember('bob', null, $ops['present'], $ops['group'], $runner, $ops['path']));
        $this->assertSame(["'/usr/bin/gpasswd' -a 'bob' render"], $commands);
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/user/add/systemUserCreate.php', [
            "'Create system user'", 'pmssEnsureIntelRenderGroupMember($user[\'name\']',
        ]);
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/userTransfer.php', [
            "if (\$cfg['dryRun'])", "pmssEnsureIntelRenderGroupMember(\$cfg['localUser']",
        ]);
    }

    public function testCommandFailureWarnsAndUsermodIsOnlyFallback(): void
    {
        $messages = [];
        $commands = [];
        $logger = $this->pmssMakeArrayLogger($messages);
        $runner = static function ($label, $command) use (&$commands): int { $commands[] = $command; return 1; };
        $path = static function (string $binary): string { return $binary === 'usermod' ? '/usr/sbin/usermod' : ''; };
        $ops = self::pmssMakeCallbacks(['intel' => true, 'group' => ['members' => []]]);
        $status = \pmssEnsureIntelRenderGroupMember('bob', $logger, $ops['intel'], $ops['group'], $runner, $path);
        $this->assertSame('failed', $status);
        $this->assertSame(["'/usr/sbin/usermod' -aG render 'bob'"], $commands);
        $this->assertTrue(strpos(implode('\n', $messages), '[WARN] Unable to add account to render group') !== false);
    }
}
