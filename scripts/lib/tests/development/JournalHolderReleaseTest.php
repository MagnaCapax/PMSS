<?php
/** Journal releases use fixture proc/sysfs trees and intercepted writes only. @license GPL-3.0-only */
declare(strict_types=1);
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/journalHolderRelease.php';

final class JournalHolderReleaseTest extends TestCase
{
    private function stat(int $pid, string $name, string $state = 'D', int $flags = 0): string
    {
        return "$pid ($name) $state 1 1 1 0 0 $flags ".str_repeat('0 ', 30)."\n";
    }

    private function frame(string $symbol): string { return "[<0>] $symbol+0x1/0x20\n"; }

    /** All children run against fresh fixture roots; a fake root identity exercises the real private logger. */
    private function cycle(array $changes = [], array $options = []): array
    {
        $root = $this->pmssMakeTempDir('pmss-journal-holder-', 0700);
        $files = ['proc/self/mountinfo' => "41 1 9:5 / /home rw - ext4 /dev/md5 rw\n",
            'sys/md5/dev' => "9:5\n", 'proc/20/stat' => $this->stat(20, 'jbd2/md5-8', 'S', 0x00200000),
            'proc/20/stack' => $this->frame('jbd2_journal_wait_updates'),
            'proc/100/stat' => $this->stat(100, 'private (task) name'),
            'proc/100/stack' => $this->frame('ext4_wait_block_bitmap'),
            'proc/100/cgroup' => "8:blkio:/user.slice/user-1001.slice/session-1.scope\n1:name=systemd:/user.slice\n0::/user.slice\n",
            'proc/100/status' => "Uid:\t1001\t1001\t1001\t1001\n",
            'proc/200/stat' => $this->stat(200, 'second-account'),
            'proc/200/stack' => $this->frame('wait_transaction_locked'),
            'proc/200/cgroup' => "8:blkio:/user.slice/user-1002.slice\n",
            'proc/200/status' => "Uid:\t1002\t1002\t1002\t1002\n",
            'blkio/user.slice/user-1001.slice/blkio.throttle.read_iops_device' => "9:5 25\n8:0 77\n",
            'blkio/user.slice/user-1002.slice/blkio.throttle.read_iops_device' => "9:5 90\n"];
        $this->pmssWriteFiles($root, array_replace($files, $changes));
        $script = <<<'PHP'
namespace JournalHolderFixture;
function posix_geteuid() { return 0; }
function file_get_contents($path) {
    if (in_array(basename($path), ['cmdline', 'environ', 'comm'], true)) throw new \RuntimeException('private task read');
    $GLOBALS['reads'][] = $path;
    return \file_get_contents($path);
}
function file_put_contents($path, $data, $flags = 0) {
    $GLOBALS['writes'][] = [$path, $data];
    return \file_put_contents($path, $data, $flags);
}
PHP;
        // Inline into one isolated namespace so read/write tracking and root identity stay local to the fixture.
        foreach (['runtime/snapshot.php', 'cgroup/directApply.php', 'cgroup/journalHolderObserve.php',
            'cgroup/journalHolderLog.php', 'cgroup/journalHolderRelease.php'] as $file) {
            $path = $this->pmssRepoPath('scripts/lib/'.$file);
            $source = str_replace(['<?php', 'declare(strict_types=1);', '__DIR__'],
                ['', '', var_export(dirname($path), true)], (string) file_get_contents($path));
            $script .= 'eval('.var_export('namespace JournalHolderFixture;'.$source, true).');';
        }
        $script .= '$root = '.var_export($root, true).'; $options = '.var_export($options, true).';';
        $script .= <<<'PHP'
$GLOBALS['reads'] = []; $GLOBALS['writes'] = []; $pauses = 0;
$pause = static function () use ($root, $options, &$pauses): bool {
    $pauses++;
    foreach ($options['after'] ?? [] as $file => $value) \file_put_contents($root.'/'.$file, $value);
    return !($options['interrupted'] ?? false);
};
$n = pmssJournalHolderReleaseCycle($options['policy'] ?? [], $options['kernel'] ?? '6.1.0-42-amd64',
    $root.'/proc', $root.'/blkio', $root.'/sys', $pause);
$log = getenv('PMSS_DYNAMIC_IOPS_LOG');
$rows = is_file($log) ? array_map(static function ($line) { return json_decode($line, true); }, file($log, FILE_IGNORE_NEW_LINES)) : [];
echo json_encode(['released' => $n, 'writes' => $GLOBALS['writes'], 'reads' => $GLOBALS['reads'], 'rows' => $rows,
    'pauses' => $pauses, 'mode' => is_file($log) ? fileperms($log) & 0777 : null,
    'other' => \file_get_contents($root.'/blkio/user.slice/user-1002.slice/blkio.throttle.read_iops_device')]);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_DYNAMIC_IOPS_LOG' => $root.'/dynamic-iops.jsonl',
            'PMSS_DRY_RUN' => !empty($options['dry']) ? '1' : '0']);
    }

    public function testStalledJournalClearsExactlyTheCappedHolderAndLogsOnlySafeFields(): void
    {
        $result = $this->cycle();
        $this->assertSame(1, $result['released']);
        $this->assertSame(1, count($result['writes']));
        $this->assertSame('9:5 0', $result['writes'][0][1]);
        $this->assertTrue(strpos($result['writes'][0][0], '/user-1001.slice/') !== false);
        $this->assertSame("9:5 90\n", $result['other']);
        $this->assertSame(0600, $result['mode']);
        $this->assertSame(1, count($result['rows']));
        $row = $result['rows'][0]; unset($row['ts']);
        $this->assertSame(['event' => 'journal-holder-release', 'uid' => 1001, 'cap_before' => 25,
            'frame' => 'ext4_wait_block_bitmap', 'stall_s' => 5, 'kernel' => '6.1.0-42-amd64', 'count_today' => 1], $row);
        foreach (['comm', 'cmdline', 'private', '/', 'second-account'] as $private) {
            $this->assertFalse(strpos(json_encode($row), $private) !== false);
        }
        $this->assertSame(2, count(array_filter($result['reads'], static function ($path) { return substr($path, -9) === '/20/stack'; })));
    }

    public function testNoStallOrSingleSampleOrInterruptedSleepCannotWrite(): void
    {
        foreach ([[['proc/20/stack' => $this->frame('schedule')], []],
            [[], ['after' => ['proc/20/stack' => $this->frame('schedule')]]],
            [[], ['interrupted' => true]]] as [$changes, $options]) {
            $result = $this->cycle($changes, $options);
            $this->assertSame([], $result['writes']); $this->assertSame([], $result['rows']);
        }
    }

    public function testUncappedOrOutsideSliceHolderLogsWithoutWriting(): void
    {
        foreach ([['blkio/user.slice/user-1001.slice/blkio.throttle.read_iops_device', "8:0 77\n", 'journal-holder-uncapped'],
            ['blkio/user.slice/user-1001.slice/blkio.throttle.read_iops_device', "9:5 0\n", 'journal-holder-uncapped'],
            ['proc/100/cgroup', "8:blkio:/\n", 'journal-holder-outside-user-slice']] as [$file, $raw, $event]) {
            $result = $this->cycle([$file => $raw]);
            $this->assertSame([], $result['writes']);
            $this->assertSame($event, $result['rows'][0]['event']);
        }
    }

    public function testKernelFilesystemSettingAndMissingJournalGatesAreQuiet(): void
    {
        foreach ([[[], ['kernel' => '6.12.0-amd64']], [[], ['kernel' => '7.0.1']], [[], ['kernel' => 'unknown']],
            [[], ['policy' => ['cgroup' => ['dynamicIops' => ['journalHolderRelease' => false]]]]],
            [[], ['dry' => true]], [['proc/20/stat' => $this->stat(20, 'jbd2/md6-8', 'S', 0x00200000)], []],
            [['proc/20/stat' => $this->stat(20, 'jbd2/md5-8')], []],
            [['proc/self/mountinfo' => "41 1 9:5 / /home rw - xfs /dev/md5 rw\n"], []]] as [$changes, $options]) {
            $result = $this->cycle($changes, $options);
            $this->assertSame([], $result['writes']); $this->assertSame([], $result['rows']);
            $this->assertSame(0, $result['pauses']);
        }
    }

    public function testMalformedSliceOrUidMismatchCannotWrite(): void
    {
        foreach (["8:blkio:/user.slice/user-0.slice\n", "bad\n", "8:blkio:/user.slice/user-1001.slice\0\n",
            "8:blkio:/user.slice/user-1002.slice\n", "8:blkio:/user.slice/user-999999999999999999999.slice\n",
            "8:blkio:/user.slice/user-1001.slice\n9:blkio:/user.slice/user-1001.slice\n",
            "8:blkio:/user.slice/user-1001.slice/user-1002.slice\n"] as $raw) {
            $this->assertSame([], $this->cycle(['proc/100/cgroup' => $raw])['writes']);
        }
        $this->assertSame([], $this->cycle(['proc/100/status' => "Uid:\t0\t1001\t1001\t1001\n"])['writes']);
    }

    public function testEveryMetadataFrameAndEveryExcludedWaiter(): void
    {
        foreach (\PMSS_JOURNAL_HOLDER_FRAMES as $frame) {
            $result = $this->cycle(['proc/100/stack' => $this->frame($frame)]);
            $this->assertSame(1, $result['released']);
            $this->assertSame($frame, $result['rows'][0]['frame']);
        }
        foreach (\PMSS_JOURNAL_HOLDER_WAITERS as $frame) {
            $result = $this->cycle(['proc/100/stack' => $this->frame('ext4_read_bh').$this->frame($frame)]);
            $this->assertSame([], $result['writes']); $this->assertSame([], $result['rows']);
        }
    }

    public function testRunningTaskStackIsNeverReadAndWorkerThreadCanBeHolder(): void
    {
        $result = $this->cycle(['proc/100/stat' => $this->stat(100, 'leader', 'S')]);
        $this->assertSame([], $result['writes']);
        foreach ($result['reads'] as $path) $this->assertFalse(substr($path, -10) === '/100/stack');
        $result = $this->cycle(['proc/100/stat' => $this->stat(100, 'leader', 'S'),
            'proc/100/task/101/stat' => $this->stat(101, 'worker'),
            'proc/100/task/101/stack' => $this->frame('ext4_read_bh'),
            'proc/100/task/101/cgroup' => "8:blkio:/user.slice/user-1001.slice\n",
            'proc/100/task/101/status' => "Uid:\t1001\t1001\t1001\t1001\n"]);
        $this->assertSame(1, $result['released']);
    }

    public function testDailyCountIncludesPriorReleasesAndRotationButNotOtherEventsOrDays(): void
    {
        $row = ['ts' => gmdate('c'), 'event' => 'journal-holder-release', 'uid' => 1001];
        $current = json_encode($row)."\n".json_encode(array_replace($row, ['event' => 'journal-holder-uncapped']))."\n";
        $rotated = json_encode($row)."\n".json_encode(array_replace($row, ['ts' => gmdate('c', time() - 86400)]))."\n";
        $result = $this->cycle(['dynamic-iops.jsonl' => $current, 'dynamic-iops.jsonl.1' => $rotated]);
        $this->assertSame(1, count($result['writes']));
        $this->assertSame(3, $result['rows'][2]['count_today']);
    }

    public function testMultipleTasksInOneSliceClearOnceAndTransactionWaitersNeverRelease(): void
    {
        $result = $this->cycle(['proc/101/stat' => $this->stat(101, 'another holder'),
            'proc/101/stack' => $this->frame('ext4_read_bh'),
            'proc/101/cgroup' => "8:blkio:/user.slice/user-1001.slice\n",
            'proc/101/status' => "Uid:\t1001\t1001\t1001\t1001\n"]);
        $this->assertSame(1, count($result['writes']));
        $this->assertSame(1, count($result['rows']));
        foreach (['R', 'S', 'Z', 'T'] as $state) {
            $result = $this->cycle(['proc/100/stat' => $this->stat(100, 'non-D', $state)]);
            $this->assertSame([], $result['writes']); $this->assertSame([], $result['rows']);
        }
    }

    public function testPositiveWriterStillRejectsZeroAndClearRefusesUnvalidatedTargets(): void
    {
        $root = $this->pmssMakeTempDir('pmss-journal-clear-', 0700);
        $path = $root.'/user.slice/user-1001.slice/blkio.throttle.read_iops_device';
        $this->pmssWriteFile($path, "9:5 25\n");
        $canonical = \pmssCgroupDirectUserBlkioFilePath(1001, 'blkio.throttle.read_iops_device');
        foreach ([0, -1, PHP_INT_MIN] as $iops) {
            $this->assertSame('invalid-target', \pmssIopsWriteThrottle($canonical, '9:5', $iops, false)['reason']);
        }
        foreach (['', "9:5\n", '9:5 1', '-9:5', '9:'] as $device) {
            $this->assertFalse(\pmssIopsClearThrottle($path, $device, false, $root)['ok']);
            $this->assertSame("9:5 25\n", file_get_contents($path));
        }
        $this->assertFalse(\pmssIopsClearThrottle($path, '9:5', false)['ok']);
        $this->assertSame('written', \pmssIopsClearThrottle($path, '9:5', false, $root)['reason']);
        $this->assertSame('9:5 0', file_get_contents($path));
    }
    public function testMalformedReleaseFlagKeepsDefaultAndNeverDropsCaps(): void
    {
        foreach (['no', 0, null, ['x']] as $value) {
            $settings = \pmssDynamicIopsSettings(['cgroup' => ['dynamicIops' => ['journalHolderRelease' => $value]]]);
            $this->assertTrue(is_array($settings));
            $this->assertSame('enforce', $settings['mode']);
            $this->assertTrue($settings['journalHolderRelease']);
        }
        $this->assertFalse(\pmssDynamicIopsSettings(['cgroup' => ['dynamicIops' => ['journalHolderRelease' => false]]])['journalHolderRelease']);
    }
}
