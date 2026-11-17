<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Real private paths and locks, with inert commands and captured diagnostics. */
class IsolationStateRecheckTest extends TestCase
{
    public function testCorrectStateIsSilentIncludingTheKernelInvisibleSpelling(): void
    {
        foreach (['rw,hidepid=2', 'rw,nosuid,hidepid=invisible'] as $mountOptions) {
            $row = $this->observe(['mountOptions' => $mountOptions]);
            $this->pmssAssertArraySubsetSame(['commands' => [], 'logs' => [], 'released' => true], $row);
        }
    }

    public function testMissingOrWeakerHidepidIsRemountedAndVerified(): void
    {
        foreach (['rw', 'rw,hidepid=0', 'rw,hidepid=1', 'rw,hidepid=off', 'rw,hidepid=2,hidepid=1'] as $mountOptions) {
            $row = $this->observe(['mountOptions' => $mountOptions]);
            $this->assertSame(['mount -o remount,hidepid=2 /proc'], $row['commands']);
            $this->assertSame([true], $row['lockedDuringCommands']);
            $this->assertSame(1, count($row['logs']));
            $this->assertStringContainsString('[REPAIR] /proc', $row['logs'][0]);
            $this->assertTrue($row['released']);
        }
    }

    public function testEveryGroupOptionRequiresTheOrderedResetAndFinalRemount(): void
    {
        foreach (['rw,hidepid=2,gid=500', 'rw,gid=1065,hidepid=invisible',
            'rw,gid=0,hidepid=2', 'rw,gid=500', 'rw,gid=500,gid=1065,hidepid=1'] as $mountOptions) {
            $row = $this->observe(['mountOptions' => $mountOptions]);
            $this->assertSame(['mount -o remount,hidepid=2,gid=0 /proc',
                'mount -o remount,hidepid=2 /proc'], $row['commands']);
            $this->assertSame([true, true], $row['lockedDuringCommands']);
            $this->assertSame(1, count($row['logs']));
        }
    }

    public function testHomeLosesOnlyOtherReadWriteAndAccountsAreUntouched(): void
    {
        foreach ([0757 => 0751, 0741 => 0741, 0751 => 0751, 02757 => 02751, 0756 => 0750] as $before => $after) {
            $row = $this->observe(['homeMode' => $before]);
            $this->assertSame($after, $row['homeMode']);
            $this->assertSame(0777, $row['accountMode']);
            $this->assertSame([], $row['commands']);
            $this->assertSame($before === $after ? 0 : 1, count($row['logs']));
        }
    }

    public function testSymlinkedHomeIsUntouched(): void
    {
        $row = $this->observe(['homeLink' => true, 'homeMode' => 0757]);
        $this->pmssAssertArraySubsetSame(['homeMode' => 0757, 'commands' => [], 'logs' => []], $row);
    }

    public function testBusyOrUnavailableUpdateLockPreventsAllRepairs(): void
    {
        foreach (['busy', 'unavailable'] as $lock) {
            $row = $this->observe(['lock' => $lock, 'homeMode' => 0757, 'recordMode' => 0644, 'mountOptions' => 'rw']);
            $this->pmssAssertArraySubsetSame(['commands' => [], 'homeMode' => 0757, 'recordMode' => 0644], $row);
            $this->assertSame(1, count($row['logs']));
            $this->assertStringContainsString('update lock '.$lock, $row['logs'][0]);
        }
    }

    public function testSessionRecordRepairsLogOnceAndConvergedRunsAreSilent(): void
    {
        $row = $this->observe(['recordMode' => 0644, 'repeat' => true]);
        $this->assertSame(0640, $row['recordMode']);
        $this->assertSame(1, count($row['logs']));
        $this->assertStringContainsString('[REPAIR] /var/log/wtmp granted other-read; mode 0644 -> 0640', $row['logs'][0]);
    }

    public function testSessionRecordExceptionsWarnAndReleaseTheLock(): void
    {
        $row = $this->observe(['recordLink' => true, 'recordMode' => 0644]);
        $this->assertSame(0644, $row['recordMode']);
        $this->assertSame(1, count($row['logs']));
        $this->assertStringContainsString('[WARN] Cannot harden permissions: /var/log/wtmp', $row['logs'][0]);
        $this->assertTrue($row['released']);
    }

    public function testDryRunMakesNoRepairsOrClaims(): void
    {
        $row = $this->observe(['dryRun' => true, 'homeMode' => 0757, 'recordMode' => 0644, 'mountOptions' => 'rw,gid=500']);
        $this->pmssAssertArraySubsetSame(['commands' => [], 'logs' => [], 'homeMode' => 0757,
            'recordMode' => 0644, 'released' => true], $row);
    }

    public function testUnchangedOrUnreadableMountTableWarnsWithoutStoppingPermissionRepairs(): void
    {
        foreach (['rw', 'rw,hidepid=1', 'rw,hidepid=2,gid=500', null, ''] as $mountOptions) {
            $row = $this->observe(['mountOptions' => $mountOptions, 'keepMounts' => true, 'homeMode' => 0757, 'recordMode' => 0644]);
            $this->assertStringContainsString('[WARN]', $row['logs'][0]);
            $this->assertSame(0751, $row['homeMode']);
            $this->assertSame(0640, $row['recordMode']);
            $this->assertTrue($row['released']);
        }
    }

    public function testFailedCommandsWarnAndThrownCommandsReleaseTheLock(): void
    {
        $row = $this->observe(['mountOptions' => 'rw,gid=500', 'rc' => 32, 'keepMounts' => true]);
        $this->assertSame(2, count($row['commands']));
        $this->assertStringContainsString('remount failed (rc=32)', $row['logs'][0]);
        $this->assertTrue($row['released']);
        $row = $this->observe(['mountOptions' => 'rw', 'throw' => true]);
        $this->assertSame('fixture runner failure', $row['error']);
        $this->assertTrue($row['released']);
    }

    public function testCronRechecksAfterRootRequirementAndBeforeSystemdAvailability(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/systemdServicesGuard.php');
        $call = strpos($source, 'pmssIsolationStateRecheck();');
        $this->assertTrue($call !== false);
        $this->assertTrue(strpos($source, 'requireRoot();') < $call);
        $this->assertTrue($call < strpos($source, 'if (!pmssSystemdRuntimeAvailable())'));
    }

    /** Run the real re-check and record helper with a namespaced, inert logger. */
    private function observe(array $options): array
    {
        $root = $this->pmssMakeTempDir('pmss-isolation-recheck-', 0700);
        $script = <<<'PHP'
namespace IsolationRecheckFixture;
use RuntimeException;
function logMessage($message) { $GLOBALS['logs'][] = $message; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/systemPrep/isolationRecheck.php',
            'IsolationRecheckFixture');
        $script .= '$root = '.var_export($root, true).'; $options = '.var_export($options + [
            'mountOptions' => 'rw,hidepid=2', 'homeMode' => 0741, 'homeLink' => false,
            'recordMode' => 0640, 'recordLink' => false, 'lock' => '', 'dryRun' => false,
            'repeat' => false, 'keepMounts' => false, 'rc' => 0, 'throw' => false,
        ], true).';';
        $script .= <<<'PHP'
$GLOBALS['logs'] = []; $commands = $locked = []; $error = null;
putenv('PMSS_DRY_RUN='.($options['dryRun'] ? '1' : '0'));
$mounts = $root.'/mounts'; $lockPath = $root.'/update.lock';
if ($options['mountOptions'] !== null) file_put_contents($mounts, $options['mountOptions'] === ''
    ? "tmpfs /tmp tmpfs rw 0 0\n" : "proc /proc proc ".$options['mountOptions']." 0 0\n");
$home = $root.($options['homeLink'] ? '/home-target' : '/home');
mkdir($home, 0700); chmod($home, $options['homeMode']);
mkdir($home.'/account', 0700); chmod($home.'/account', 0777);
if ($options['homeLink']) symlink($home, $root.'/home');
mkdir($root.'/var/log', 0700, true);
$record = $root.($options['recordLink'] ? '/record-target' : '/var/log/wtmp');
file_put_contents($record, 'fixture'); chmod($record, $options['recordMode']);
if ($options['recordLink']) symlink($record, $root.'/var/log/wtmp');
if ($options['lock'] === 'unavailable') $lockPath = $home;
$held = $options['lock'] === 'busy' ? \pmssLockFileAcquire($lockPath, true) : false;
$runner = static function ($command) use (&$commands, &$locked, $mounts, $lockPath, $options): int {
    $commands[] = $command;
    $other = \pmssLockFileAcquire($lockPath, true, 'c', false, true, $busy);
    $locked[] = $other === false && $busy;
    \pmssLockHandleRelease($other);
    if ($options['throw']) throw new RuntimeException('fixture runner failure');
    if (!$options['keepMounts']) file_put_contents($mounts, "proc /proc proc rw,hidepid=invisible 0 0\n");
    return $options['rc'];
};
try {
    pmssIsolationStateRecheck($lockPath, $mounts, $root, $runner);
    if ($options['repeat']) pmssIsolationStateRecheck($lockPath, $mounts, $root, $runner);
} catch (\Throwable $failure) { $error = $failure->getMessage(); }
\pmssLockHandleRelease($held);
$after = \pmssLockFileAcquire($lockPath, true);
$released = $after !== false; \pmssLockHandleRelease($after);
clearstatcache();
echo json_encode(['commands' => $commands, 'logs' => $GLOBALS['logs'], 'lockedDuringCommands' => $locked,
    'homeMode' => fileperms($home) & 07777, 'accountMode' => fileperms($home.'/account') & 07777,
    'recordMode' => fileperms($record) & 07777, 'released' => $released, 'error' => $error]);
PHP;
        return $this->pmssRunInlinePhpJson($script);
    }
}
