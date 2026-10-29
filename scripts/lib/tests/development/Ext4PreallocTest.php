<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise boot tuning against fixture proc/sys trees, never host paths. */
class Ext4PreallocTest extends TestCase
{
    public function testDecisionCoversStripesAndKernelBoundaries(): void
    {
        foreach ([512, 640, 768, 1024, 1536, 2048, 2560, 3072, 3584] as $stripe) {
            foreach (['4.19.0-27-amd64', '5.10.0', '6.1.0-51-amd64', '6.7.12'] as $kernel) {
                $result = $this->decision((string)$stripe, (string)$stripe, $kernel);
                $this->assertSame((string)($stripe - 1), $result);
                $this->assertTrue((int)$result % $stripe !== 0);
                $this->assertSame('skip:already-unaligned', $this->decision($result, (string)$stripe, $kernel));
            }
        }
        foreach (['6.8.0', '6.8-rc1', '6.12.0', '7.0.0'] as $kernel) {
            $this->assertSame('skip:fixed-kernel', $this->decision('1536', '1536', $kernel));
        }
        $this->assertSame('3071', $this->decision('3072', '1536', '6.1'));
        $this->assertSame('skip:already-unaligned', $this->decision('512', '1536', '6.1'));
        $this->assertSame('4294967294', $this->decision('4294967295', '3', '6.1'));
    }

    public function testDecisionRejectsUnsafeInputs(): void
    {
        foreach (['0', '1'] as $stripe) {
            $this->assertSame('skip:no-stripe', $this->decision('512', $stripe, '6.1'));
        }
        foreach (['', '0', '-1', '1.5', 'abc', '4294967296', '999999999999999999999'] as $value) {
            $this->assertSame('skip:invalid-values', $this->decision($value, '512', '6.1'));
        }
        foreach (['', '-1', '1.5', 'abc', '4294967296'] as $stripe) {
            $this->assertSame('skip:invalid-values', $this->decision('512', $stripe, '6.1'));
        }
        foreach (['', 'unknown', '6', '6.bad', '6.8bad'] as $kernel) {
            $this->assertSame('skip:unknown-kernel', $this->decision('512', '512', $kernel));
        }
    }

    public function testApplyCoversEachSuperblockAndIsIdempotent(): void
    {
        $root = $this->fixture(['md1' => ['1536', '1536'], 'vda2' => ['512', '512'], 'sda1' => ['1', '512']]);
        $rows = $this->apply($root);
        $this->assertSame(['md1', 'sda1', 'vda2'], array_column($rows, 'device'));
        $this->assertSame(['applied', 'skip:no-stripe', 'applied'], array_column($rows, 'result'));
        $this->assertSame([1535, 512, 511], array_column($rows, 'after'));
        $this->assertSame('1535', trim(file_get_contents($root.'/sys/md1/mb_group_prealloc')));
        $again = $this->apply($root);
        $this->assertSame(['skip:already-unaligned', 'skip:no-stripe', 'skip:already-unaligned'], array_column($again, 'result'));
    }

    public function testMissingAndInvalidPathsAreAuditedWithoutWrites(): void
    {
        $root = $this->fixture(['md1' => ['1536', null], 'vda2' => ['bad', '512'], 'sda1' => ['512', 'bad']]);
        $rows = $this->apply($root);
        $this->assertSame(['skip:missing-prealloc', 'skip:invalid-values', 'skip:invalid-values'], array_column($rows, 'result'));
        $this->assertSame(null, $rows[0]['after']);
        $this->assertSame(null, $rows[1]['before']);
        $this->assertSame(null, $rows[2]['stripe']);
        $this->assertTrue(!file_exists($root.'/sys/md1/mb_group_prealloc'));
        $this->assertSame('512', trim(file_get_contents($root.'/sys/vda2/mb_group_prealloc')));
        $this->assertSame([], $this->apply($this->fixture([])));
    }

    public function testFixedKernelPreservesLiveValue(): void
    {
        $root = $this->fixture(['md1' => ['1536', '1536']]);
        $rows = $this->apply($root, '', '6.8.0');
        $this->assertSame('skip:fixed-kernel', $rows[0]['result']);
        $this->assertSame(1536, $rows[0]['after']);
    }

    public function testFailedWritesAndReadbackMismatchAreAudited(): void
    {
        $root = $this->fixture(['md1' => ['1536', '1536']]);
        // Inject a failing write builtin, leaving all effects inside the fixture.
        $rows = $this->apply($root, 'printf() { if [ "$1" = "%s\\n" ]; then return 1; fi; command printf "$@"; }');
        $this->assertSame('write-failed', $rows[0]['result']);
        $this->assertSame(null, $rows[0]['after']);

        $this->pmssWriteFile($root.'/sys/md1/mb_group_prealloc', "1536\n");
        // Emulate a kernel accepting the write but retaining a different value.
        $rows = $this->apply($root, 'printf() { if [ "$1" = "%s\\n" ]; then command printf "1536\\n"; else command printf "$@"; fi; }');
        $this->assertSame('readback-mismatch', $rows[0]['result']);
        $this->assertSame(1536, $rows[0]['after']);
    }

    public function testMarkerIsFirstAndAbsentMarkerExecutesNothing(): void
    {
        $source = file_get_contents($this->pmssRepoPath('etc/seedbox/config/template.pmss-boot-tuning.sh'));
        $marker = 'if [ -f /etc/seedbox/config/ext4-group-prealloc.enabled ]; then';
        $start = strpos($source, $marker);
        $end = strpos($source, '# Record the detected host profile', $start);
        $this->assertTrue($start !== false && $end !== false);
        $block = substr($source, $start, $end - $start);
        $root = $this->fixture([]);
        $block = str_replace('/etc/seedbox/config/ext4-group-prealloc.enabled', $root.'/absent', $block);
        $this->assertSame('untouched', $this->pmssRunShellHarness("#!/bin/sh\n".$block."\nprintf '%s' \"\${ext4_prealloc_audit:-untouched}\"\n"));
        // With a marker but missing helper, audit the delivery problem explicitly.
        $this->pmssWriteFile($root.'/absent', '');
        $block = str_replace('/scripts/lib/update/systemPrep/ext4Prealloc.sh', $root.'/missing-helper', $block);
        $this->assertSame('[{"result":"skip:missing-helper"}]', $this->pmssRunShellHarness("#!/bin/sh\n".$block."\nprintf '%s' \"\$ext4_prealloc_audit\"\n"));
        $this->assertStringContainsString('"ext4_group_prealloc": ${ext4_prealloc_audit:-[]}', $source);
    }

    private function decision(string $current, string $stripe, string $kernel): string
    {
        $args = $this->pmssBuildArgvCommand([$current, $stripe, $kernel]);
        return $this->pmssRunShellHarness($this->preamble().'pmss_ext4_prealloc_decide '.$args."\n");
    }

    private function preamble(): string
    {
        return "#!/bin/sh\n. ".escapeshellarg($this->pmssRepoPath('scripts/lib/update/systemPrep/ext4Prealloc.sh'))."\n";
    }

    private function apply(string $root, string $injection = '', string $kernel = '6.1.0'): array
    {
        $json = $this->pmssRunShellHarness($this->preamble().$injection."\n".'pmss_ext4_prealloc_apply '
            .escapeshellarg($root.'/proc').' '.escapeshellarg($root.'/sys').' '.escapeshellarg($kernel)."\n");
        $rows = json_decode($json, true);
        $this->assertTrue(is_array($rows), 'invalid audit JSON: '.$json);
        return $rows;
    }

    private function fixture(array $mounts): string
    {
        $root = $this->pmssMakeTempDir('pmss-ext4-prealloc-');
        foreach ($mounts as $dev => $values) {
            $this->pmssWriteFile($root.'/proc/'.$dev.'/options', "rw\nstripe=".$values[0]."\n", 0700);
            // Even an absent knob retains its device directory for the missing-read case.
            $this->pmssEnsureDir($root.'/sys/'.$dev, 0700);
            if ($values[1] !== null) {
                $this->pmssWriteFile($root.'/sys/'.$dev.'/mb_group_prealloc', $values[1]."\n", 0700);
            }
        }
        return $root;
    }
}
