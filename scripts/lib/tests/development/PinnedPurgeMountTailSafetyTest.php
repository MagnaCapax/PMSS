<?php
/** Verify pinned purge mount discovery refuses incomplete post-separator fields. */
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class PinnedPurgeMountTailSafetyTest extends TestCase
{
    private function fixture(): array
    {
        $base = $this->pmssMakeTempDir('pmss-purge-mount-tail-');
        $home = $base.'/fixtur01';
        $this->pmssWriteFile($home.'/remote/sentinel', 'remote data');
        // Every unmount stays in this private fixture and records literal argv.
        $this->pmssWriteExecutableFile($base.'/bin/umount', <<<'SH'
#!/bin/sh
[ "$#" -eq 5 ] && [ "$1" = --internal-only ] && [ "$2" = --no-canonicalize ] && [ "$3" = --lazy ] && [ "$4" = -- ] || exit 99
printf '%s\0' "$5" >> "$PMSS_TEST_UMOUNT_LOG"
SH
        );
        $mountinfo = $base.'/mountinfo';
        $command = 'cd '.escapeshellarg($home).' && bash '
            .escapeshellarg($this->pmssRepoPath('scripts/lib/pathSafety/mountsDetach.sh'))
            .' '.escapeshellarg($home).' '.escapeshellarg($mountinfo);
        // Bind the stub environment to the shell running the entire command chain.
        $command = 'sh -c '.escapeshellarg($command);
        $env = $this->pmssPathPrefixedEnvironment($base.'/bin', ['PMSS_TEST_UMOUNT_LOG' => $base.'/unmounted']);
        return [$base, $home, $mountinfo, $command, $env];
    }

    public function testMalformedTailsRefuseTheEntireSnapshotBeforeUnmounting(): void
    {
        [$base, $home, $mountinfo, $command, $env] = $this->fixture();
        $root = '25 1 0:42 / '.$home." rw - fuse.fixture fixture rw\n";
        $queued = '26 25 0:42 / '.$home."/remote rw - fuse.fixture fixture rw\n";
        // These rows have newlines, so the existing final-fragment guard cannot reject them.
        foreach (['rw - ', 'rw - fuse.fixture', 'rw - fuse.fixture fixture',
            'rw shared:7 - fuse.fixture', 'rw shared:7 - fuse.fixture fixture',
            'rw - fuse.fixture fixture rw unexpected', 'rw shared:7 - fuse.fixture fixture rw unexpected'] as $tail) {
            // Refuse corrupt descendants and unrelated rows, regardless of queue order.
            foreach ([$home.'/remote', $base.'/outside'] as $target) {
                $invalid = '27 25 0:42 / '.$target.' '.$tail."\n";
                foreach ([$root.$invalid, $invalid.$queued, $queued.$invalid] as $snapshot) {
                    $this->pmssWriteFile($mountinfo, $snapshot);
                    $this->assertSame(1, $this->pmssExecShellCommand($command, $env)['rc']);
                    $this->assertFalse(file_exists($base.'/unmounted'));
                    $this->assertSame('remote data', file_get_contents($home.'/remote/sentinel'));
                }
            }
        }
    }

    public function testCompleteTailsPreserveOptionalFieldsEscapedSourcesAndUnmountArguments(): void
    {
        [$base, $home, $mountinfo, $command, $env] = $this->fixture();
        // Preserve complete rows without interpreting optional tags or escaped source bytes.
        $tails = ['rw - fuse.fixture fixture rw', 'rw shared:7 - ext4 /dev/fixture rw,errors=continue',
            'rw master:7 propagate_from:3 - tmpfs none rw,size=4096k',
            'ro unbindable - fuse.fixture - ro', 'rw future_tag:7 - fuse.fixture source\040name rw',
            "rw shared:7 -  fuse.fixture\tfixture\trw  "];
        foreach ($tails as $tail) {
            $this->pmssWriteFile($mountinfo, '26 25 0:42 / '.$home.'/remote '.$tail."\n");
            $this->assertSame(0, $this->pmssExecShellCommand($command, $env)['rc']);
        }
        $this->assertSame(str_repeat($home."/remote\0", count($tails)), file_get_contents($base.'/unmounted'));
        $this->assertSame('remote data', file_get_contents($home.'/remote/sentinel'));
    }
}
