<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** zswap follows the presence of swap, and "swap on non-rotational storage" sees through partitions and md members. */
class BootTuningSwapZswapTest extends TestCase
{
    private const TEMPLATE = 'etc/seedbox/config/template.pmss-boot-tuning.sh';
    private const GATE = 'if [ "$swap_present" = "1" ] && [ -f /sys/module/zswap/parameters/enabled ]; then';

    public function testZswapIsEnabledWheneverSwapExists(): void
    {
        $body = $this->pmssReadRepoFile(self::TEMPLATE);
        $this->assertStringContainsString(self::GATE."\n\tmodprobe zswap", $body);
        $this->pmssAssertStringNotContainsString('if [ "$swap_fast" = "1" ] && [ -f /sys/module/zswap', $body);
    }

    public function testHardwareSummaryReportsZswapRequestedFromSwapPresence(): void
    {
        $this->assertStringContainsString(self::GATE."\n\t\tzswap_requested=1", $this->pmssReadRepoFile(self::TEMPLATE));
    }

    public function testPoolSizeAndCompressorAreUnchanged(): void
    {
        $body = $this->pmssReadRepoFile(self::TEMPLATE);
        foreach (['write_sys /sys/module/zswap/parameters/max_pool_percent 10', 'echo lz4 >/sys/module/zswap/parameters/compressor',
            'echo zstd >/sys/module/zswap/parameters/compressor', 'write_sys /sys/module/zswap/parameters/shrinker_enabled Y'] as $line) {
            $this->assertStringContainsString($line, $body);
        }
    }

    public function testSwapPartitionOnAnSsdCountsAsFast(): void
    {
        $this->assertSame('1', $this->fast('sda5'));
    }

    public function testSwapPartitionOnAnHddIsNotFast(): void
    {
        $this->assertSame('0', $this->fast('sdb3'));
    }

    public function testWholeDisksKeepTheirOwnFlag(): void
    {
        $this->assertSame(['sda' => '1', 'sdb' => '0'], ['sda' => $this->fast('sda'), 'sdb' => $this->fast('sdb')]);
    }

    public function testMdOfRotationalMembersIsNotFast(): void
    {
        $this->assertSame('0', $this->fast('md0'));
    }

    public function testMdWithAnSsdMemberIsFast(): void
    {
        $this->assertSame('1', $this->fast('md1'));
    }

    public function testMdOverPartitionsOfARotationalDiskIsNotFast(): void
    {
        $this->assertSame('0', $this->fast('md2'));
    }

    public function testAnUnknownDeviceIsNeverFast(): void
    {
        $this->assertSame('0', $this->fast('nosuchdev'));
    }

    /** Source the template's own lookup function against the sysfs fixture and print 1 or 0 for one device name. */
    private function fast(string $name): string
    {
        $body = $this->pmssReadRepoFile(self::TEMPLATE);
        $this->assertSame(1, preg_match('/^sys_class_block=.*?^block_is_fast\(\) \{\n.*?^\}\n/ms', $body, $m), 'lookup function moved');
        $script = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-boot-tuning-swap-').'/lookup.sh',
            $m[0]."\nif block_is_fast ".escapeshellarg($name)."; then echo 1; else echo 0; fi\n");
        $root = $this->sysfsFixture();
        return trim($this->pmssRunShellCommand('sh '.escapeshellarg($script), ['PMSS_SYSCTL_SYS_CLASS_BLOCK_PATH' => $root.'/class/block'], '2>&1'));
    }

    /** A sysfs-shaped tree: class/block entries are symlinks into devices/block, parents hold queue/. */
    private function sysfsFixture(): string
    {
        $root = $this->pmssMakeTempDir('pmss-boot-tuning-sysfs-');
        // disk => [rotational flag, partitions]
        foreach (['sda' => ['0', ['sda5']], 'sdb' => ['1', ['sdb3']], 'sdc' => ['1', ['sdc1']], 'sdd' => ['0', ['sdd1']]] as $disk => [$rot, $parts]) {
            $this->pmssWriteFile($root.'/devices/block/'.$disk.'/queue/rotational', $rot."\n");
            $this->link('../../devices/block/'.$disk, $root.'/class/block/'.$disk);
            foreach ($parts as $part) {
                $this->pmssEnsureDir($root.'/devices/block/'.$disk.'/'.$part);
                $this->link('../../devices/block/'.$disk.'/'.$part, $root.'/class/block/'.$part);
            }
        }
        // md devices carry their own queue/ and list members under slaves/.
        foreach (['md0' => ['sdc'], 'md1' => ['sdc', 'sdd'], 'md2' => ['sdc']] as $md => $disks) {
            $this->pmssWriteFile($root.'/devices/block/'.$md.'/queue/rotational', "1\n");
            $this->link('../../devices/block/'.$md, $root.'/class/block/'.$md);
            foreach ($disks as $disk) {
                $this->pmssEnsureDir($root.'/devices/block/'.$md.'/slaves');
                $this->link('../../'.$disk.'/'.$disk.'1', $root.'/devices/block/'.$md.'/slaves/'.$disk.'1');
            }
        }
        return $root;
    }

    private function link(string $target, string $path): void
    {
        $this->pmssEnsureDir(dirname($path));
        $this->assertTrue(@symlink($target, $path), 'Unable to create fixture link '.$path);
    }
}
