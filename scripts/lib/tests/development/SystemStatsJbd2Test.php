<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/systemStats.php';

/** Hermetic coverage of the `jbd2_home` stats-log field: parser, /home resolution and log placement. */
class SystemStatsJbd2Test extends TestCase
{
    /** Verbatim /proc/fs/jbd2/md1-8/info from a Linux 6.12 host (note the trailing space after "average:"). */
    private const REAL_INFO = "5 transactions (2 requested), each up to 1365 blocks\naverage: \n  0ms waiting for transaction\n"
        ."  0ms request delay\n  3472ms running transaction\n  0ms transaction was being locked\n"
        ."  0ms flushing data (in ordered mode)\n  0ms logging transaction\n  2602us average transaction commit time\n"
        ."  12473 handles per transaction\n  11 blocks per transaction\n  13 logged blocks per transaction\n";

    private const MOUNTINFO = "29 1 9:2 / / rw,noatime shared:1 - ext4 /dev/md2 rw,stripe=32\n"
        ."45 29 9:1 / /boot rw,noatime shared:47 - ext3 /dev/md1 rw,stripe=32\n"
        ."48 29 9:3 / /home rw,noatime shared:85 - ext4 /dev/md3 rw,stripe=32\n";

    public function testParserReadsRealKernelSample(): void
    {
        $this->assertSame('5/2/0/0/3472/0/0/0/2602', \pmssSystemStatsJbd2FromRaw(self::REAL_INFO));
        $this->assertSame('140093/109522/0/0/3236/0/0/0/1145', \pmssSystemStatsJbd2FromRaw(
            "140093 transactions (109522 requested), each up to 87381 blocks\naverage: \n  0ms waiting for transaction\n  0ms request delay\n"
            ."  3236ms running transaction\n  0ms transaction was being locked\n  0ms flushing data (in ordered mode)\n"
            ."  0ms logging transaction\n  1145us average transaction commit time\n  198 handles per transaction\n"));
        // CRLF and surrounding whitespace do not change the projection.
        $this->assertSame('5/2/0/0/3472/0/0/0/2602', \pmssSystemStatsJbd2FromRaw(str_replace("\n", "\r\n", self::REAL_INFO)));
    }

    public function testParserWithoutTransactionsKeepsCountersAndMarksAveragesNa(): void
    {
        $this->assertSame('0/0/na/na/na/na/na/na/na', \pmssSystemStatsJbd2FromRaw("0 transactions (0 requested), each up to 1365 blocks\n"));
        // Averages printed against zero transactions are stale and are not trusted.
        $this->assertSame('0/0/na/na/na/na/na/na/na', \pmssSystemStatsJbd2FromRaw("0 transactions (0 requested), each up to 1365 blocks\n  7ms waiting for transaction\n"));
    }

    public function testParserDegradesWholeFieldWhenCountersAreUnusable(): void
    {
        $tail = substr(self::REAL_INFO, strpos(self::REAL_INFO, "\n"));
        $big = PHP_INT_MAX.'0';
        foreach ([null, '', "\n", "garbage\n".$tail, "5 transactions (2 requested)\n".$tail, "x transactions (2 requested), each up to 1 blocks\n".$tail,
            "5 transactions (y requested), each up to 1 blocks\n".$tail, "-5 transactions (2 requested), each up to 1 blocks\n".$tail,
            $big." transactions (2 requested), each up to 1 blocks\n".$tail, "5 transactions (".$big." requested), each up to 1 blocks\n".$tail,
            "5 transactions (2 requested), each up to 1 blocks\0\n".$tail, " 5 transactions (2 requested), each up to 1 blocks\n".$tail,
            "5 transactions (2 requested), each up to 1 blocks trailing\n".$tail, self::REAL_INFO."\0"] as $raw) {
            $this->assertSame('na', \pmssSystemStatsJbd2FromRaw($raw), (string) json_encode($raw));
        }
    }

    public function testParserMarksOnlyTheBadSubValueNa(): void
    {
        $head = "5 transactions (2 requested), each up to 1365 blocks\naverage: \n";
        $cases = [
            ['  xms waiting for transaction', '5/2/na/0/3472/0/0/0/2602'],
            ['  1.5ms waiting for transaction', '5/2/na/0/3472/0/0/0/2602'],
            ['  -3ms waiting for transaction', '5/2/na/0/3472/0/0/0/2602'],
            // The unit is part of the contract: microseconds where milliseconds are expected stay unused.
            ['  9us waiting for transaction', '5/2/na/0/3472/0/0/0/2602'],
            ['  '.PHP_INT_MAX.'0ms waiting for transaction', '5/2/na/0/3472/0/0/0/2602'],
            ['  7ms waiting for transaction', '5/2/7/0/3472/0/0/0/2602'],
        ];
        foreach ($cases as [$replacement, $expected]) {
            $raw = str_replace('  0ms waiting for transaction', $replacement, self::REAL_INFO);
            $this->assertSame($expected, \pmssSystemStatsJbd2FromRaw($raw), $replacement);
        }
        // A commit time in ms instead of us, a missing row and a malformed row each cost only their own slot.
        $this->assertSame('5/2/0/0/3472/0/0/0/na', \pmssSystemStatsJbd2FromRaw(str_replace('2602us', '2602ms', self::REAL_INFO)));
        $this->assertSame('5/2/0/0/na/0/0/0/2602', \pmssSystemStatsJbd2FromRaw(str_replace("  3472ms running transaction\n", '', self::REAL_INFO)));
        $this->assertSame('5/2/0/0/na/0/0/0/2602', \pmssSystemStatsJbd2FromRaw(str_replace('3472ms running', 'lots running', self::REAL_INFO)));
        // Rows are located by label, so a reordered kernel output projects identically, first duplicate wins.
        $this->assertSame('5/2/0/0/3472/0/0/0/2602', \pmssSystemStatsJbd2FromRaw($head."  2602us average transaction commit time\n  3472ms running transaction\n"
            ."  0ms waiting for transaction\n  0ms request delay\n  0ms transaction was being locked\n  0ms flushing data (in ordered mode)\n"
            ."  0ms logging transaction\n  99ms running transaction\n"));
        $this->assertSame('5/2/na/na/na/na/na/na/na', \pmssSystemStatsJbd2FromRaw($head));
    }

    public function testMountinfoResolvesOnlyAJournaledHomeMount(): void
    {
        $this->assertSame('9:3', \pmssSystemStatsJbd2HomeDeviceFromMountinfo(self::MOUNTINFO));
        $this->assertSame('9:3', \pmssSystemStatsJbd2HomeDeviceFromMountinfo(str_replace('ext4 /dev/md3', 'ext3 /dev/md3', self::MOUNTINFO)));
        // Optional fields are variable in number and the separator is located after them.
        $this->assertSame('253:7', \pmssSystemStatsJbd2HomeDeviceFromMountinfo("1 0 253:7 /sub /home rw shared:1 master:2 propagate_from:3 - ext4 /dev/mapper/x rw\n"));
        $this->assertSame('8:1', \pmssSystemStatsJbd2HomeDeviceFromMountinfo("1 0 8:1 / /home rw - ext4 /dev/sda1 rw\n"));
        foreach (['xfs', 'btrfs', 'ext2', 'tmpfs', 'nfs4', 'overlay', 'EXT4'] as $type) {
            $this->assertSame(null, \pmssSystemStatsJbd2HomeDeviceFromMountinfo(str_replace('ext4 /dev/md3', $type.' /dev/md3', self::MOUNTINFO)), $type);
        }
    }

    public function testMountinfoRejectsMissingShadowedAndMalformedHomeRows(): void
    {
        $root = "29 1 9:2 / / rw - ext4 /dev/md2 rw\n";
        $this->assertSame(null, \pmssSystemStatsJbd2HomeDeviceFromMountinfo($root));
        $this->assertSame(null, \pmssSystemStatsJbd2HomeDeviceFromMountinfo(null));
        $this->assertSame(null, \pmssSystemStatsJbd2HomeDeviceFromMountinfo(''));
        $this->assertSame(null, \pmssSystemStatsJbd2HomeDeviceFromMountinfo("48 29 9:3 / /home2 rw - ext4 /dev/md3 rw\n48 29 9:3 / /home/user rw - ext4 /dev/md3 rw\n"));
        // The later mount is the visible one, whichever filesystem it is.
        $this->assertSame(null, \pmssSystemStatsJbd2HomeDeviceFromMountinfo(self::MOUNTINFO."50 48 0:50 / /home rw - tmpfs tmpfs rw\n"));
        $this->assertSame('9:4', \pmssSystemStatsJbd2HomeDeviceFromMountinfo(self::MOUNTINFO."50 48 9:4 / /home rw - ext4 /dev/md4 rw\n"));
        foreach (["48 29 9:3 / /home rw - ext4\n", "48 29 9:3 / /home rw ext4 /dev/md3 rw\n", "48 29 x:3 / /home rw - ext4 /dev/md3 rw\n",
            "48 29 9:3 / /home rw - ext4 /dev/md3 rw\0\n", "48 29 9: / /home rw - ext4 /dev/md3 rw\n", "48 29 9:3\n- ext4 /dev/md3\n", "- - - - /home - ext4 x\n"] as $raw) {
            $this->assertSame(null, \pmssSystemStatsJbd2HomeDeviceFromMountinfo($raw), (string) json_encode($raw));
        }
    }

    public function testDirectorySelectionNeedsExactlyOneInternalJournal(): void
    {
        $entries = ['.', '..', 'md3-8', 'md31-8', 'md3p1-8', 'dm-0-8', 'dm-1-8', 'sda1-8', 'nvme0n1p1-8', 'cciss!c0d0p1-8', 'sdb1'];
        foreach (['md3' => 'md3-8', 'dm-0' => 'dm-0-8', 'sda1' => 'sda1-8', 'nvme0n1p1' => 'nvme0n1p1-8', 'cciss!c0d0p1' => 'cciss!c0d0p1-8'] as $device => $expected) {
            $this->assertSame($expected, \pmssSystemStatsJbd2DirectoryFor($device, $entries), $device);
        }
        // Prefixes of other names, an external journal (no inode suffix) and absent devices never match.
        foreach (['md', 'sda', 'sdb1', 'md4', 'dm-', 'dm', 'nvme0n1'] as $device) {
            $this->assertSame(null, \pmssSystemStatsJbd2DirectoryFor($device, $entries), $device);
        }
        // Two journals for one name is ambiguous, not a guess.
        $this->assertSame(null, \pmssSystemStatsJbd2DirectoryFor('md3', ['md3-8', 'md3-9']));
        foreach (['', '.', '..', '../md3', 'md3/x', "md3\0", 'md 3', 'md3-8'.PHP_EOL, 'md.*'] as $device) {
            $this->assertSame(null, \pmssSystemStatsJbd2DirectoryFor($device, $entries), (string) json_encode($device));
        }
        $this->assertSame(null, \pmssSystemStatsJbd2DirectoryFor('md3', []));
    }

    /** @return array{0:string,1:string,2:string,3:string} mountinfo file, sysfs dev-block dir, jbd2 dir, root. */
    private function fixtureTree(string $mountinfo, array $journals = ['md3-8' => self::REAL_INFO], string $link = '9:3', string $target = '../../devices/virtual/block/md3'): array
    {
        $root = $this->pmssMakeTempDir('pmss-stats-jbd2-');
        $this->pmssEnsureDir($root.'/sysdev', 0755);
        $this->pmssEnsureDir($root.'/jbd2', 0755);
        $this->pmssWriteFile($root.'/mountinfo', $mountinfo);
        if ($link !== '') $this->pmssCreateSymlinkOrSkip($target, $root.'/sysdev/'.$link);
        foreach ($journals as $name => $info) {
            $this->pmssEnsureDir($root.'/jbd2/'.$name, 0755);
            if ($info !== null) $this->pmssWriteFile($root.'/jbd2/'.$name.'/info', $info);
        }
        return [$root.'/mountinfo', $root.'/sysdev', $root.'/jbd2', $root];
    }

    public function testReadResolvesHomeDeviceToItsJournalAndNotOtherFilesystems(): void
    {
        [$mountinfo, $sys, $jbd2] = $this->fixtureTree(self::MOUNTINFO, [
            'md3-8' => self::REAL_INFO, 'md2-8' => str_replace('5 transactions (2', '9 transactions (9', self::REAL_INFO),
        ]);
        $this->assertSame('5/2/0/0/3472/0/0/0/2602', \pmssSystemStatsJbd2HomeRead($mountinfo, $sys, $jbd2));
        // A device-mapper style kernel name comes from the sysfs link target, not from the mount source.
        [$mountinfo, $sys, $jbd2] = $this->fixtureTree(str_replace('9:3', '253:0', self::MOUNTINFO), ['dm-0-8' => self::REAL_INFO], '253:0', '../../devices/virtual/block/dm-0');
        $this->assertSame('5/2/0/0/3472/0/0/0/2602', \pmssSystemStatsJbd2HomeRead($mountinfo, $sys, $jbd2));
        // Partitions are linked below their parent disk; the journal is named for the partition.
        [$mountinfo, $sys, $jbd2] = $this->fixtureTree(str_replace('9:3', '259:2', self::MOUNTINFO), ['nvme0n1p1-8' => self::REAL_INFO], '259:2', '../../devices/pci0000:00/0000:00:01.0/nvme/nvme0/nvme0n1/nvme0n1p1');
        $this->assertSame('5/2/0/0/3472/0/0/0/2602', \pmssSystemStatsJbd2HomeRead($mountinfo, $sys, $jbd2));
    }

    public function testReadIsNaWhenHomeIsNotAJournaledMountOrAnyStepIsMissing(): void
    {
        foreach (['xfs', 'btrfs', 'tmpfs'] as $type) {
            [$mountinfo, $sys, $jbd2] = $this->fixtureTree(str_replace('ext4 /dev/md3', $type.' /dev/md3', self::MOUNTINFO));
            $this->assertSame('na', \pmssSystemStatsJbd2HomeRead($mountinfo, $sys, $jbd2), $type);
        }
        [$mountinfo, $sys, $jbd2] = $this->fixtureTree("29 1 9:2 / / rw - ext4 /dev/md2 rw\n");
        $this->assertSame('na', \pmssSystemStatsJbd2HomeRead($mountinfo, $sys, $jbd2), 'no /home mount');
        [$mountinfo, $sys, $jbd2] = $this->fixtureTree(self::MOUNTINFO, []);
        $this->assertSame('na', \pmssSystemStatsJbd2HomeRead($mountinfo, $sys, $jbd2), 'no journal directory');
        [$mountinfo, $sys, $jbd2] = $this->fixtureTree(self::MOUNTINFO, ['md3-8' => null]);
        $this->assertSame('na', \pmssSystemStatsJbd2HomeRead($mountinfo, $sys, $jbd2), 'journal directory without info');
        [$mountinfo, $sys, $jbd2] = $this->fixtureTree(self::MOUNTINFO, ['md3-8' => self::REAL_INFO], '');
        $this->assertSame('na', \pmssSystemStatsJbd2HomeRead($mountinfo, $sys, $jbd2), 'no sysfs link for the device');
        [$mountinfo, $sys, $jbd2] = $this->fixtureTree(self::MOUNTINFO, ['md3-8' => self::REAL_INFO, 'md3-9' => self::REAL_INFO]);
        $this->assertSame('na', \pmssSystemStatsJbd2HomeRead($mountinfo, $sys, $jbd2), 'ambiguous journals');
        [$mountinfo, $sys, $jbd2, $root] = $this->fixtureTree(self::MOUNTINFO);
        // Absent or non-regular inputs at each step (the shared readers suppress their own warnings with @).
        $this->assertSame('na', \pmssSystemStatsJbd2HomeRead($root.'/absent', $sys, $jbd2), 'absent mountinfo');
        $this->assertSame('na', \pmssSystemStatsJbd2HomeRead($root, $sys, $jbd2), 'mountinfo is a directory');
        $this->assertSame('na', \pmssSystemStatsJbd2HomeRead($root.'/mountinfo', $sys, $root.'/absent'), 'absent jbd2 root');
        $this->assertSame('na', \pmssSystemStatsJbd2HomeRead($root.'/mountinfo', $root.'/absent', $jbd2), 'absent sysfs dir');
    }

    public function testReadKeepsParserDegradationForMalformedInfo(): void
    {
        [$mountinfo, $sys, $jbd2] = $this->fixtureTree(self::MOUNTINFO, ['md3-8' => "not a journal\n"]);
        $this->assertSame('na', \pmssSystemStatsJbd2HomeRead($mountinfo, $sys, $jbd2));
        [$mountinfo, $sys, $jbd2] = $this->fixtureTree(self::MOUNTINFO, ['md3-8' => str_replace('3472ms', 'lotsms', self::REAL_INFO)]);
        $this->assertSame('5/2/0/0/na/0/0/0/2602', \pmssSystemStatsJbd2HomeRead($mountinfo, $sys, $jbd2));
    }

    public function testLogLineAppendsJbd2HomeLastWithoutMovingLegacyFields(): void
    {
        $legacy = ['load' => 'a', 'cpuIowait' => 'b', 'memTotal' => 'c', 'memFree' => 'd', 'memCache' => 'e', 'memBuffers' => 'f', 'swapTotal' => 'g',
            'swapFree' => 'h', 'diskBusy' => 'i', 'iopingRoot' => 'j', 'iopingHome' => 'k', 'topMem' => 'l', 'psiIo' => 'm', 'psiMem' => 'n'];
        $before = '20250613 09:10:11 | load:a | cpu_iowait:b | mem_total:c | mem_free:d | mem_cache:e | mem_buffers:f | swap_total:g | swap_free:h'
            .' | disk_busy:i | ioping_root:j | ioping_home:k | top_mem:l | psi_io:m | psi_mem:n';
        $with = \pmssSystemStatsJbd2FromRaw(self::REAL_INFO);
        $this->assertSame($before.' | jbd2_home:5/2/0/0/3472/0/0/0/2602', \pmssSystemStatsLogLine($legacy + ['jbd2Home' => $with], '20250613 09:10:11'));
        // A stats array without the key (older callers) still renders a parseable trailing na.
        $this->assertSame($before.' | jbd2_home:na', \pmssSystemStatsLogLine($legacy, '20250613 09:10:11'));
    }

    public function testCollectedFieldIsNaOrNineSlashJoinedCounters(): void
    {
        $value = \pmssSystemStatsCollect()['jbd2Home'];
        $this->assertMatches('#^(?:na|[0-9]+/[0-9]+(?:/(?:na|[0-9]+)){7})$#', $value);
    }
}
