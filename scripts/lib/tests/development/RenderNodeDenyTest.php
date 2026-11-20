<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/renderNodeAccess.php';

/** Inject all hardware, account and command observations; execute only on testing machines. */
class RenderNodeDenyTest extends TestCase
{
    public function testRuleContainsOnlyDisabledValidatedExistingAccountsAndRepairsUnchangedAcls(): void
    {
        $target = $this->pmssMakeTempDir('pmss-render-deny-').'/74-pmss-render-node-deny.rules';
        $messages = $commands = $lookups = [];
        $ops = self::pmssMakeCallbacks(['intel' => true, 'users' => [
            'alice' => ['gpuEnabled' => false], 'bob' => ['gpuEnabled' => 'false'],
            'carol' => [], 'missing' => ['gpuEnabled' => false], 'bad;id' => ['gpuEnabled' => false],
            "bad\n" => ['gpuEnabled' => false], 'broken' => false,
        ], 'nodes' => ['/dev/dri/renderD128', '/dev/dri/renderD129']]);
        $lookup = static function ($name) use (&$lookups) {
            $lookups[] = $name;
            return $name === 'missing' ? false : ['uid' => $name === 'alice' ? 1001 : 1002];
        };
        $runner = static function ($label, $command) use (&$commands): int {
            $commands[] = $command;
            $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => "user:1001:---\nuser:1002:---\n"];
            return 0;
        };
        $path = static function ($binary): string { return '/usr/bin/'.$binary; };
        $logger = $this->pmssMakeArrayLogger($messages);
        for ($pass = 0; $pass < 2; $pass++) {
            \pmssEnsureIntelRenderNodeDenies($logger, $target, $runner, $path, $ops['intel'], $ops['users'], $lookup, $ops['nodes']);
            $this->assertSame(
                'SUBSYSTEM=="drm", KERNEL=="renderD*", DRIVERS=="i915|xe|amdgpu", RUN+="/usr/bin/setfacl -m u:alice:--- $devnode"'."\n"
                .'SUBSYSTEM=="drm", KERNEL=="renderD*", DRIVERS=="i915|xe|amdgpu", RUN+="/usr/bin/setfacl -m u:bob:--- $devnode"'."\n",
                file_get_contents($target));
            $this->assertSame(0644, fileperms($target) & 0777);
        }
        $this->assertSame(['alice', 'bob', 'missing', 'alice', 'bob', 'missing'], $lookups);
        $this->assertSame(14, count($commands));
        $this->assertSame([
            "'/usr/bin/udevadm' control --reload",
            "'/usr/bin/udevadm' trigger --subsystem-match=drm --action=change",
            "'/usr/bin/udevadm' settle",
            "'/usr/bin/getfacl' --numeric --omit-header --absolute-names '/dev/dri/renderD128'",
        ], array_slice($commands, 0, 4));
        $this->assertTrue(in_array('Intel render denies: 2 accounts, 2 verified, 0 missing', $messages, true));
    }

    public function testEmptyListCreatesNoRuleAndRemovesOldRuleBeforeReload(): void
    {
        $target = $this->pmssMakeTempDir('pmss-render-empty-').'/rule';
        $messages = $commands = [];
        $ops = self::pmssMakeCallbacks(['intel' => true, 'users' => ['alice' => []], 'path' => '/usr/bin/udevadm']);
        $runner = static function ($label, $command) use (&$commands, $target): int {
            if (file_exists($target)) throw new \RuntimeException('Rule was not removed before reload');
            $commands[] = $command;
            return 0;
        };
        $logger = $this->pmssMakeArrayLogger($messages);
        \pmssEnsureIntelRenderNodeDenies($logger, $target, $runner, $ops['path'], $ops['intel'], $ops['users']);
        $this->assertFalse(file_exists($target));
        $this->assertSame([], $commands);
        $this->pmssWriteFile($target, 'old deny');
        \pmssEnsureIntelRenderNodeDenies($logger, $target, $runner, $ops['path'], $ops['intel'], $ops['users']);
        $this->assertFalse(file_exists($target));
        $this->assertSame(3, count($commands));
        $this->assertTrue(in_array('Intel render denies: 0 accounts, 0 verified, 0 missing', $messages, true));
    }

    public function testNonIntelAndDryRunSkipStoreAndFileMutations(): void
    {
        $target = $this->pmssMakeTempDir('pmss-render-skip-').'/rule';
        $this->pmssWriteFile($target, 'keep');
        $messages = [];
        $ops = self::pmssMakeCallbacks(['no' => false, 'yes' => true]);
        $unexpected = static function (): array { throw new \RuntimeException('Unexpected observation'); };
        $logger = $this->pmssMakeArrayLogger($messages);
        \pmssEnsureIntelRenderNodeDenies($logger, $target, $unexpected, $unexpected, $ops['no'], $unexpected);
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], static function () use ($logger, $target, $unexpected, $ops): void {
            \pmssEnsureIntelRenderNodeDenies($logger, $target, $unexpected, $unexpected, $ops['yes'], $unexpected);
        });
        $this->assertSame('keep', file_get_contents($target));
        $this->assertSame(['[SKIP] Dry run: Intel render-node denies unchanged'], $messages);
    }

    public function testUnsafeManagedTargetIsNotReplacedOrActivated(): void
    {
        $root = $this->pmssMakeTempDir('pmss-render-unsafe-');
        $this->pmssWriteFile($root.'/keep', 'keep');
        symlink($root.'/keep', $root.'/rule');
        $messages = $commands = [];
        $ops = self::pmssMakeCallbacks(['yes' => true, 'users' => ['alice' => ['gpuEnabled' => false]], 'account' => ['uid' => 1001]]);
        \pmssEnsureIntelRenderNodeDenies($this->pmssMakeArrayLogger($messages), $root.'/rule', $this->pmssMakeStepRunner($commands), null, $ops['yes'], $ops['users'], $ops['account']);
        $this->assertSame('keep', file_get_contents($root.'/keep'));
        $this->assertSame([], $commands);
        $this->assertStringContainsString('[WARN]', implode("\n", $messages));
    }

    public function testOnlyInvalidOrAbsentAccountsLeaveNoRule(): void
    {
        $target = $this->pmssMakeTempDir('pmss-render-invalid-').'/rule';
        $messages = $commands = [];
        $ops = self::pmssMakeCallbacks(['intel' => true, 'users' => [
            'bad;id' => ['gpuEnabled' => false], '../alice' => ['gpuEnabled' => false],
            'missing' => ['gpuEnabled' => false], 'nouid' => ['gpuEnabled' => false],
        ]]);
        $lookup = static function ($name) { return $name === 'nouid' ? [] : false; };
        \pmssEnsureIntelRenderNodeDenies($this->pmssMakeArrayLogger($messages), $target, $this->pmssMakeStepRunner($commands), null, $ops['intel'], $ops['users'], $lookup);
        $this->assertFalse(file_exists($target));
        $this->assertSame([], $commands);
    }

    public function testDefaultLoaderUsesDurableConfigInsteadOfTheHomeList(): void
    {
        $root = $this->pmssMakeTempDir('pmss-render-store-');
        $this->pmssWriteFiles($root.'/config/users', [
            'alice.json' => '{"gpuEnabled":false}', 'bob.json' => '{"gpuEnabled":true}',
        ]);
        $messages = $commands = [];
        $ops = self::pmssMakeCallbacks(['intel' => true, 'account' => ['uid' => 1001], 'nodes' => [], 'path' => '/usr/bin/udevadm']);
        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $root.'/config'], function () use ($root, $ops, &$messages, &$commands): void {
            \pmssEnsureIntelRenderNodeDenies($this->pmssMakeArrayLogger($messages), $root.'/rule', $this->pmssMakeStepRunner($commands), $ops['path'], $ops['intel'], null, $ops['account'], $ops['nodes']);
        });
        $this->assertSame('SUBSYSTEM=="drm", KERNEL=="renderD*", DRIVERS=="i915|xe|amdgpu", RUN+="/usr/bin/setfacl -m u:alice:--- $devnode"'."\n", file_get_contents($root.'/rule'));
    }
}
