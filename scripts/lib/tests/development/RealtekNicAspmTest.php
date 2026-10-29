<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

/** The Realtek NIC ASPM rule must be exact and trigger only Realtek Ethernet devices after a change. */
class RealtekNicAspmTest extends TestCase
{
    public function testRuleIsExactAndTriggerRunsOnlyAfterChange(): void
    {
        $root = $this->pmssMakeTempDir('pmss-realtek-aspm-', 0700);
        $target = $root.'/71-pmss-realtek-nic-aspm.rules';
        $messages = [];
        $commands = [];
        $logger = $this->pmssMakeArrayLogger($messages);
        $runner = $this->pmssMakeStepRunner($commands);
        $path = self::pmssMakeCallbacks(['path' => '/usr/bin/udevadm'])['path'];

        \pmssEnsureRealtekNicAspmOff($logger, $target, $runner, $path);
        $match = 'ACTION=="add|bind|change", SUBSYSTEM=="pci", ATTR{vendor}=="0x10ec", ATTR{class}=="0x020000"';
        $this->assertSame(
            "# PMSS-managed: keep PCIe ASPM off on Realtek Ethernet controllers (RTL8111/8168 transmit queue stalls).\n"
                .$match.', TEST=="link/l1_aspm", ATTR{link/l1_aspm}="0"'."\n"
                .$match.', TEST=="link/l0s_aspm", ATTR{link/l0s_aspm}="0"'."\n",
            file_get_contents($target)
        );
        $this->assertSame(0644, fileperms($target) & 0777);
        $this->assertSame([
            ['Reloading udev rules for Realtek NIC ASPM', "'/usr/bin/udevadm' control --reload"],
            ['Applying Realtek NIC ASPM rule', "'/usr/bin/udevadm' trigger --subsystem-match=pci --attr-match=vendor=0x10ec --attr-match=class=0x020000 --action=change"],
        ], $commands);

        \pmssEnsureRealtekNicAspmOff($logger, $target, $runner, $path);
        $this->assertSame(2, count($commands));
        $this->assertTrue(in_array('[SKIP] Realtek NIC ASPM rule already present and up to date', $messages, true));
    }

    public function testMissingUdevadmWarnsWithoutRunningCommands(): void
    {
        $root = $this->pmssMakeTempDir('pmss-realtek-aspm-missing-udev-', 0700);
        $messages = [];
        $commands = [];
        $logger = $this->pmssMakeArrayLogger($messages);
        $runner = static function (string $description, string $command) use (&$commands): int { $commands[] = $command; return 0; };
        $path = self::pmssMakeCallbacks(['path' => ''])['path'];
        \pmssEnsureRealtekNicAspmOff($logger, $root.'/rule', $runner, $path);
        $this->assertSame([], $commands);
        $this->assertTrue(in_array('[WARN] udevadm unavailable; Realtek NIC ASPM rule will apply on the next device event', $messages, true));
    }
}
