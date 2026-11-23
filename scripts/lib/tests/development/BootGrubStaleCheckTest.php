<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/bootGrubStale.php';

/** The GRUB boot-safety check reports a grub-pc upgrade newer than the running boot and writes nothing (GH #691, ADR 0340). */
class BootGrubStaleCheckTest extends TestCase
{
    private const BOOT = '2026-06-21 00:00:00';

    /** @return array{0:string,1:array<int,string>} fixture dir and the messages the check logs */
    private function grubCheck(array $logs, ?string $stat = null): array
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set("UTC");
        $dir = $this->pmssMakeTempDir("pmss-grub-stale-");
        $paths = [];
        foreach ($logs as $name => $text) {
            $this->pmssWriteFile($dir.'/'.$name, $text);
            $paths[] = $dir.'/'.$name;
        }
        $this->pmssWriteFile($dir.'/stat', $stat ?? "cpu  1 2 3\nbtime ".strtotime(self::BOOT)."\nprocesses 9\n");
        $messages = [];
        $result = \pmssBootGrubStaleCoreImgCheck(function ($message) use (&$messages) {
            $messages[] = $message;
        }, $paths, $dir.'/stat');
        date_default_timezone_set($zone);
        return [$result, $messages];
    }

    /** The issue's scenario: grub-pc upgraded after the host booted warns once, naming the upgrade. */
    public function testUpgradeAfterBootWarns(): void
    {
        [$result, $messages] = $this->grubCheck(['dpkg.log' => "2026-06-21 06:06:49 upgrade grub-pc:amd64 2.06-13+deb12u1 2.06-13+deb12u2\n"]);
        $this->assertSame('warn', $result);
        $this->assertSame(1, count($messages));
        $this->assertStringContainsString('[WARN] GRUB boot-safety check', $messages[0]);
        $this->assertStringContainsString('2026-06-21 06:06:49 UTC', $messages[0]);
        $this->assertStringContainsString('grub-install on every boot disk', $messages[0]);
    }

    /** A host that rebooted after the upgrade has already proved its MBR: no warning. */
    public function testUpgradeBeforeBootIsQuiet(): void
    {
        [$result, $messages] = $this->grubCheck(['dpkg.log' => "2026-06-20 23:59:59 upgrade grub-pc:amd64 2.06-13+deb12u1 2.06-13+deb12u2\n"]);
        $this->assertSame('ok', $result);
        $this->assertStringContainsString('[SKIP]', $messages[0]);
    }

    /** The module package counts too, and so does the rotated previous log. */
    public function testBinPackageAndPreviousLogCount(): void
    {
        [$result] = $this->grubCheck([
            'dpkg.log.1' => "2026-06-22 01:00:00 upgrade grub-pc-bin:amd64 2.06-13+deb12u1 2.06-13+deb12u2\n",
            'dpkg.log' => "2026-06-22 01:00:05 status installed grub-pc-bin:amd64 2.06-13+deb12u2\n",
        ]);
        $this->assertSame('warn', $result);
    }

    /** Only upgrades of the BIOS packages count: installs, other packages and EFI GRUB are ignored. */
    public function testOtherRowsAreIgnored(): void
    {
        [$result] = $this->grubCheck(['dpkg.log' => implode("\n", [
            '2026-06-22 01:00:00 install grub-pc:amd64 <none> 2.06-13+deb12u2',
            '2026-06-22 01:00:01 upgrade grub-common:amd64 2.06-13+deb12u1 2.06-13+deb12u2',
            '2026-06-22 01:00:02 upgrade grub-efi-amd64-bin:amd64 2.06-13+deb12u1 2.06-13+deb12u2',
            '2026-06-22 01:00:03 upgrade grub-pc-extra:amd64 1 2',
            '2026-06-22 01:00:04 remove grub-pc:amd64 2.06-13+deb12u2 <none>',
            'upgrade grub-pc:amd64 2.06-13+deb12u1 2.06-13+deb12u2',
            '',
        ])]);
        $this->assertSame('ok', $result);
    }

    /** Unreadable evidence is a skip, never a warning and never an exception. */
    public function testMissingInputsSkip(): void
    {
        $messages = [];
        $log = function ($message) use (&$messages) {
            $messages[] = $message;
        };
        $this->assertSame('skip', \pmssBootGrubStaleCoreImgCheck($log, [], '/nonexistent/pmss-stat'));
        $dir = $this->pmssMakeTempDir('pmss-grub-stale-');
        $this->pmssWriteFile($dir.'/stat', "cpu 1\nbtime notanumber\n");
        $this->assertSame('skip', \pmssBootGrubStaleCoreImgCheck($log, [], $dir.'/stat'));
        $this->pmssWriteFile($dir.'/stat', "btime ".strtotime(self::BOOT)."\n");
        $this->assertSame('ok', \pmssBootGrubStaleCoreImgCheck($log, [$dir.'/missing.log'], $dir.'/stat'));
        $this->assertSame(3, count($messages));
    }

    /** An existing log that cannot be used makes a quiet result partial, and says so instead of passing silently. */
    public function testUnusableLogIsReportedNotSilentlyIgnored(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-grub-stale-');
        $this->pmssWriteFile($dir.'/stat', "btime ".strtotime(self::BOOT)."\n");
        $this->pmssWriteFile($dir.'/big.log', str_repeat('x', PMSS_BOOT_GRUB_LOG_MAX_BYTES + 1));
        $messages = [];
        $result = \pmssBootGrubStaleCoreImgCheck(function ($message) use (&$messages) {
            $messages[] = $message;
        }, [$dir.'/big.log', $dir.'/absent.log'], $dir.'/stat');
        $this->assertSame('ok', $result);
        $this->assertSame(2, count($messages));
        $this->assertStringContainsString('1 dpkg log file(s) unreadable or too large; the result is partial', $messages[0]);
        $unusable = -1;
        \pmssBootGrubUpgradeTimes([$dir.'/absent.log'], $unusable);
        $this->assertSame(0, $unusable, 'a missing rotated log is normal and not counted');
    }

    /** Several upgrades since boot (a security bump, then a fix) are counted and the newest one is named. */
    public function testSeveralUpgradesAreCountedAndTheNewestNamed(): void
    {
        [$result, $messages] = $this->grubCheck(['dpkg.log' => implode("\n", [
            '2026-06-20 23:00:00 upgrade grub-pc:amd64 1 2',
            '2026-06-21 05:00:00 upgrade grub-pc:amd64 2 3',
            '2026-06-22 07:30:00 upgrade grub-pc-bin:amd64 2 3',
            '',
        ])]);
        $this->assertSame('warn', $result);
        $this->assertStringContainsString('2 time(s)', $messages[0]);
        $this->assertStringContainsString('2026-06-22 07:30:00 UTC', $messages[0]);
    }

    /** A host with no dpkg history at all (new install, wiped log) is quiet, and a binary or garbage log neither warns nor throws. */
    public function testNoLogsAndGarbageLogsAreQuiet(): void
    {
        [$none, $noneMessages] = $this->grubCheck([]);
        $this->assertSame('ok', $none);
        $this->assertStringContainsString('[SKIP]', $noneMessages[0]);
        [$garbage] = $this->grubCheck(['dpkg.log' => "\0\xff\xfe upgrade grub-pc\n".str_repeat("not a dpkg row\n", 50)]);
        $this->assertSame('ok', $garbage);
    }

    /** The step runs after the boot-setting steps and before the /home permission step, in the same update batch. */
    public function testStepSitsAfterBootDefaultsInTheUpdateOrder(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/update-step2.php');
        $this->assertTrue(strpos($source, "'pmssEnsureBootDefaults'") < strpos($source, "'pmssBootGrubStaleCoreImgCheck'"));
        $this->assertTrue(strpos($source, "'pmssBootGrubStaleCoreImgCheck'") < strpos($source, "Restricting world access to /home"));
    }

    /** An upgrade stamped exactly at boot time is not provably older than the boot, so it warns. */
    public function testUpgradeAtBootBoundaryWarns(): void
    {
        [$result] = $this->grubCheck(['dpkg.log' => self::BOOT." upgrade grub-pc:amd64 1 2\n"]);
        $this->assertSame('warn', $result);
    }

    /** Read-only contract: the detector source holds no writer and no command execution. */
    public function testDetectorNeverWrites(): void
    {
        $code = $this->pmssReadRepoFile('scripts/lib/update/systemPrep/bootGrubStale.php');
        $code = preg_replace('#/\*.*?\*/#s', '', $code);
        foreach (['runStep', 'exec(', 'shell_exec', 'system(', 'passthru', 'proc_open', 'popen', 'file_put_contents', 'fopen', 'unlink(', 'rename(', 'chmod(', 'touch('] as $writer) {
            $this->assertStringNotContainsString($writer, $code, 'the check must stay read-only');
        }
    }

    /** The check runs in the update as a soft-fail step, so a failure cannot stop the update. */
    public function testUpdateStepIsWiredAsSoftFail(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/util/update-step2.php',
            "pmssRunProfiledCallable('Checking GRUB boot safety', 'pmssBootGrubStaleCoreImgCheck', ['logmsg'], PMSS_UPDATE_STEP_CLASS_SOFT_FAIL);");
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/systemPrep.php', "'systemPrep/bootGrubStale.php'");
    }
}
