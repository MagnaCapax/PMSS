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
        $logger = static function (string $message) use (&$messages): void { $messages[] = $message; };
        $runner = static function (string $description, string $command) use (&$commands): int {
            $commands[] = [$description, $command];
            return 0;
        };
        $path = static function (string $binary): string { return '/usr/bin/udevadm'; };

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
        $logger = static function (string $message) use (&$messages): void { $messages[] = $message; };
        $runner = static function (string $description, string $command) use (&$commands): int { $commands[] = $command; return 0; };
        $path = static function (string $binary): string { return ''; };
        \pmssEnsureIntelRenderNodeAccess($logger, $root.'/rule', $runner, $path);
        $this->assertSame([], $commands);
        $this->assertTrue(in_array('[WARN] udevadm unavailable; Intel render-node rule will apply on the next device event', $messages, true));
    }
}
