<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

/** The i915 enable_dc=0 pin must land on Intel display hosts and in every initrd (ADR 0096). */
class I915DisplayPowerTest extends TestCase
{
    private const CONTENT = "# PMSS-managed: keep i915 display power states (DC5/DC6) off. With the DMC firmware loaded,\n"
        ."# some headless Intel minis hang about 20 s after every boot (MCE broadcast timeout). ADR 0096.\n"
        ."options i915 enable_dc=0\n";

    /** Fake initramfs-tools: an "initrd" is a text listing; update-initramfs appends $entry to each. */
    private function fakeTools(string $root, string $entry, string $updateBody = ''): array
    {
        $bin = $root.'/bin';
        $update = $updateBody !== '' ? $updateBody : "for f in '".$root."/boot'/initrd.img-*; do printf '%s\\n' '".$entry."' >> \"\$f\"; done\n";
        $this->pmssWriteExecutableFiles($bin, [
            'lsinitramfs' => "#!/bin/sh\ncat \"\$1\"\n",
            'update-initramfs' => "#!/bin/sh\n".$update,
        ], 0700, 0700);
        return [$bin.'/lsinitramfs', $bin.'/update-initramfs'];
    }

    private function shellRunner(array &$commands): callable
    {
        return static function (string $description, string $command, array $skip = []) use (&$commands): int {
            $commands[] = $description;
            exec($command.' 2>/dev/null', $output, $rc);
            return $rc;
        };
    }

    public function testNonIntelHostWritesNothingAndRunsNothing(): void
    {
        $root = $this->pmssMakeTempDir('pmss-i915-nonintel-', 0700);
        $commands = [];
        \pmssEnsureI915DisplayPowerStatesOff(self::pmssMakeCallbacks(['log' => null])['log'], $root.'/dc.conf', self::pmssMakeCallbacks(['intelDisplay' => false])['intelDisplay'], $this->shellRunner($commands));
        $this->assertFalse(file_exists($root.'/dc.conf'));
        $this->assertSame([], $commands);
    }

    public function testIntelHostWritesOptionAndRebuildsOnlyMissingInitrds(): void
    {
        $root = $this->pmssMakeTempDir('pmss-i915-rebuild-', 0700);
        $target = $root.'/etc/modprobe.d/pmss-i915-dc.conf';
        $entry = ltrim($target, '/');
        [$ls, $update] = $this->fakeTools($root, $entry);
        $boot = $this->pmssWriteFiles($root.'/boot', ['initrd.img-6.1.0-41-amd64' => "usr/lib/modules\n", 'initrd.img-6.1.0-40-amd64' => $entry."\n"], 0700);
        $messages = [];
        $commands = [];
        $path = static function (string $name) use ($ls, $update): string { return $name === 'lsinitramfs' ? $ls : $update; };

        \pmssEnsureI915DisplayPowerStatesOff($this->pmssMakeArrayLogger($messages), $target, self::pmssMakeCallbacks(['intelDisplay' => true])['intelDisplay'], $this->shellRunner($commands), $path, $boot);

        $this->assertSame(self::CONTENT, file_get_contents($target));
        $this->assertSame(0644, fileperms($target) & 0777);
        $this->assertSame([
            'Checking initrd.img-6.1.0-40-amd64 for the i915 power-state option',
            'Checking initrd.img-6.1.0-41-amd64 for the i915 power-state option',
            'Rebuilding initramfs for the i915 power-state option',
            'Checking initrd.img-6.1.0-40-amd64 for the i915 power-state option',
            'Checking initrd.img-6.1.0-41-amd64 for the i915 power-state option',
        ], $commands);
        $this->assertStringContainsString($entry, file_get_contents($boot.'/initrd.img-6.1.0-41-amd64'));
        foreach ($messages as $message) $this->assertStringNotContainsString('[WARN]', $message);

        // Second run: file up to date and every initrd carries it, so no rebuild.
        $commands = [];
        \pmssEnsureI915DisplayPowerStatesOff($this->pmssMakeArrayLogger($messages), $target, self::pmssMakeCallbacks(['intelDisplay' => true])['intelDisplay'], $this->shellRunner($commands), $path, $boot);
        $this->assertSame(2, count($commands));
        $this->assertTrue(in_array('[SKIP] i915 display power-state option already present and up to date', $messages, true));
    }

    public function testRebuildThatLeavesAnInitrdWithoutTheOptionWarns(): void
    {
        $root = $this->pmssMakeTempDir('pmss-i915-stillmissing-', 0700);
        $target = $root.'/etc/modprobe.d/pmss-i915-dc.conf';
        [$ls, $update] = $this->fakeTools($root, ltrim($target, '/'), "exit 0\n");
        $boot = $this->pmssWriteFiles($root.'/boot', ['initrd.img-6.1.0-41-amd64' => "usr/lib/modules\n"], 0700);
        $messages = [];
        $commands = [];
        $path = static function (string $name) use ($ls, $update): string { return $name === 'lsinitramfs' ? $ls : $update; };
        \pmssEnsureI915DisplayPowerStatesOff($this->pmssMakeArrayLogger($messages), $target, self::pmssMakeCallbacks(['intelDisplay' => true])['intelDisplay'], $this->shellRunner($commands), $path, $boot);
        $this->assertTrue(in_array('[WARN] i915 power-state option still missing from initrd.img-6.1.0-41-amd64', $messages, true));
    }

    public function testFailedRebuildAndMissingToolsWarnWithoutClaimingSuccess(): void
    {
        $root = $this->pmssMakeTempDir('pmss-i915-fail-', 0700);
        $target = $root.'/etc/modprobe.d/pmss-i915-dc.conf';
        [$ls, $update] = $this->fakeTools($root, ltrim($target, '/'), "exit 1\n");
        $boot = $this->pmssWriteFiles($root.'/boot', ['initrd.img-6.1.0-41-amd64' => "usr/lib/modules\n"], 0700);
        $messages = [];
        $commands = [];
        $path = static function (string $name) use ($ls, $update): string { return $name === 'lsinitramfs' ? $ls : $update; };
        \pmssEnsureI915DisplayPowerStatesOff($this->pmssMakeArrayLogger($messages), $target, self::pmssMakeCallbacks(['intelDisplay' => true])['intelDisplay'], $this->shellRunner($commands), $path, $boot);
        $this->assertTrue(in_array('[WARN] update-initramfs failed; the i915 power-state option is not in every initrd yet', $messages, true));

        $messages = [];
        $commands = [];
        \pmssEnsureI915DisplayPowerStatesOff($this->pmssMakeArrayLogger($messages), $root.'/other.conf', self::pmssMakeCallbacks(['intelDisplay' => true])['intelDisplay'], $this->shellRunner($commands), self::pmssMakeCallbacks(['path' => ''])['path'], $boot);
        $this->assertSame(self::CONTENT, file_get_contents($root.'/other.conf'));
        $this->assertSame([], $commands);
        $this->assertTrue(in_array('[WARN] initramfs-tools unavailable; the i915 power-state option is not confirmed in the initrd', $messages, true));
    }

    public function testUpdateStep2RunsTheStepAfterRenderAccess(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/util/update-step2.php',
            ["'pmssEnsureIntelRenderGroupMembers'", "'pmssEnsureI915DisplayPowerStatesOff'"]);
    }
}
