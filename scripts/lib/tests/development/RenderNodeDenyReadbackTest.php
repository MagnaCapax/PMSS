<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/renderNodeAccess.php';

/** A successful udev trigger alone must never be reported as a verified deny. */
class RenderNodeDenyReadbackTest extends TestCase
{
    public function testReadbackRequiresAnExactDenyOnEveryNodeAndSuccessfulGetfacl(): void
    {
        foreach ([
            ["user:1001:---\n", 0, ['/dev/dri/renderD128'], '', true],
            ['', 0, ['/dev/dri/renderD128'], '', false],
            ["user:1001:rw-\n", 0, ['/dev/dri/renderD128'], '', false],
            ["user:1002:---\n", 0, ['/dev/dri/renderD128'], '', false],
            ["user:1001:---\n", 1, ['/dev/dri/renderD128'], '', false],
            ["user:1001:---\n", 0, [], '', false],
            ["user:1001:---\n", 0, ['/dev/dri/renderD128'], 'getfacl', false],
            ["user:1001:---\n", 0, ['/dev/dri/renderD128', '/dev/dri/renderD129'], '', false],
        ] as [$output, $rc, $nodes, $absent, $verified]) {
            $target = $this->pmssMakeTempDir('pmss-render-readback-').'/rule';
            $messages = [];
            $ops = self::pmssMakeCallbacks(['intel' => true, 'users' => ['alice' => ['gpuEnabled' => false]], 'account' => ['uid' => 1001], 'nodes' => $nodes]);
            $runner = static function ($label, $command) use ($output, $rc): int {
                if (strpos($command, 'getfacl') === false) return 0;
                $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => strpos($command, 'renderD129') === false ? $output : ''];
                return $rc;
            };
            $path = static function ($binary) use ($absent): string { return $binary === $absent ? '' : '/usr/bin/'.$binary; };
            \pmssEnsureIntelRenderNodeDenies($this->pmssMakeArrayLogger($messages), $target, $runner, $path, $ops['intel'], $ops['users'], $ops['account'], $ops['nodes']);
            $this->assertSame(!$verified, in_array('[WARN] Intel render deny missing for alice', $messages, true));
            $summary = 'Intel render denies: 1 accounts, '.($verified ? '1 verified, 0 missing' : '0 verified, 1 missing');
            $this->assertSame(1, count(array_keys($messages, $summary, true)));
        }
    }

    public function testUdevFailuresAndMissingToolsWarnAndStillReadBack(): void
    {
        foreach ([
            ['control --reload', '', '[WARN] Unable to reload udev rules for Intel render denies'],
            ['trigger ', '', '[WARN] Unable to trigger DRM devices for Intel render denies'],
            [' settle', '', '[WARN] Unable to wait for Intel render-node denies'],
            ['', 'udevadm', '[WARN] udevadm unavailable; Intel render-node denies await the next device event'],
        ] as [$fail, $absent, $warning]) {
            $target = $this->pmssMakeTempDir('pmss-render-failure-').'/rule';
            $messages = $commands = [];
            $ops = self::pmssMakeCallbacks(['intel' => true, 'users' => ['alice' => ['gpuEnabled' => false]], 'account' => ['uid' => 1001], 'nodes' => ['/dev/dri/renderD128']]);
            $runner = static function ($label, $command) use (&$commands, $fail): int {
                $commands[] = $command;
                // Simulate a failed setfacl RUN: trigger itself can still return success.
                $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => ''];
                return $fail !== '' && strpos($command, $fail) !== false ? 1 : 0;
            };
            $path = static function ($binary) use ($absent): string { return $binary === $absent ? '' : '/usr/bin/'.$binary; };
            \pmssEnsureIntelRenderNodeDenies($this->pmssMakeArrayLogger($messages), $target, $runner, $path, $ops['intel'], $ops['users'], $ops['account'], $ops['nodes']);
            $this->assertTrue(in_array($warning, $messages, true));
            $this->assertTrue(in_array('[WARN] Intel render deny missing for alice', $messages, true));
            $this->assertStringContainsString('getfacl', end($commands));
            if ($fail === 'control --reload') $this->assertSame(2, count($commands));
        }
    }
}
