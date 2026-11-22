<?php
/** Verify pinned termination purges detach nested mounts without traversing them. */
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/terminationCleanup.php';

final class TerminateUserMountBoundaryTest extends TestCase
{
    private function fixture(): array
    {
        $base = $this->pmssMakeTempDir('pmss-terminate-mounts-');
        $home = $base.'/fixtur01';
        $this->pmssWriteFile($home.'/remote/sentinel', 'remote data');
        $this->pmssWriteExecutableFile($base.'/bin/umount', <<<'SH'
#!/bin/sh
[ "$#" -eq 5 ] && [ "$1" = --internal-only ] && [ "$2" = --no-canonicalize ] && [ "$3" = --lazy ] && [ "$4" = -- ] || exit 99
printf '%s\0' "$5" >> "$PMSS_TEST_UMOUNT_LOG"
[ "${PMSS_TEST_UMOUNT_FAIL:-0}" = 0 ] || exit 17
if [ "$5" = "${PMSS_TEST_REMOTE_MOUNT:-}" ]; then
    mv -- "$5" "$PMSS_TEST_DETACHED" && mkdir -- "$5" || exit 18
fi
SH
        );
        $this->pmssWriteFile($base.'/mountinfo', $this->row($home));
        return [$base, $home, $base.'/mountinfo', [
            'PATH' => $base.'/bin:'.getenv('PATH'), 'PMSS_TEST_UMOUNT_LOG' => $base.'/unmounted',
        ]];
    }

    private function row(string $target): string
    {
        $encoded = strtr($target, ["\\" => '\134', ' ' => '\040', "\t" => '\011', "\n" => '\012']);
        return '25 1 0:42 / '.$encoded." rw - fuse.fixture fixture rw\n";
    }

    private function detachCommand(string $home, string $mountinfo): string
    {
        return 'bash '.escapeshellarg($this->pmssRepoPath('scripts/lib/pathSafety/mountsDetach.sh'))
            .' '.escapeshellarg($home).' '.escapeshellarg($mountinfo);
    }

    private function purgeCommand(string $home, string $mountinfo, bool $clear): string
    {
        $command = \pmssPinnedTreePurgeCommand($home, $clear);
        $detach = $this->detachCommand($home, $mountinfo);
        $original = substr($detach, 0, -strlen(' '.escapeshellarg($mountinfo)));
        $this->assertStringContainsString($original, $command);
        return 'sh -c '.escapeshellarg(str_replace($original, $detach, $command));
    }

    public function testMountSelectionIsDeepestFirstAndDecodesNamesWithoutShellEvaluation(): void
    {
        [$base, $home, $mountinfo, $env] = $this->fixture();
        $special = $home.'/remote/space 1tab'."\t1line\n1".'\\040$(touch injected);\'quote';
        $this->pmssWriteFile($mountinfo, $this->row($home).$this->row($home.'2/remote')
            .$this->row($home.'/remote').$this->row($special).$this->row($base.'/outside')
            .$this->row($home.'/remote').$this->row($home.'/remote/.hidden..name'));
        $command = 'sh -c '.escapeshellarg('cd '.escapeshellarg($home).' && '.$this->detachCommand($home, $mountinfo));
        $this->assertSame(0, $this->pmssExecShellCommand($command, $env)['rc']);
        $this->assertSame([$special, $home.'/remote/.hidden..name', $home.'/remote', $home.'/remote', ''],
            explode("\0", file_get_contents($base.'/unmounted')));
        $this->assertFalse(file_exists($home.'/injected'));
    }

    public function testFakeMountIsDetachedBeforeRemovalAndRemoteContentsSurvive(): void
    {
        [$base, $home, $mountinfo, $env] = $this->fixture();
        $this->pmssWriteFile($mountinfo, $this->row($home.'/remote'));
        $env += ['PMSS_TEST_REMOTE_MOUNT' => $home.'/remote', 'PMSS_TEST_DETACHED' => $base.'/detached'];
        $this->assertSame(0, $this->pmssExecShellCommand($this->purgeCommand($home, $mountinfo, false), $env)['rc']);
        $this->assertFalse(file_exists($home));
        $this->assertSame('remote data', file_get_contents($base.'/detached/sentinel'));
        $this->assertSame($home."/remote\0", file_get_contents($base.'/unmounted'));
    }

    public function testDetachFailureStopsBothRemovalAndImmutableWalk(): void
    {
        [$base, $home, $mountinfo, $env] = $this->fixture();
        $this->pmssWriteFile($mountinfo, $this->row($home.'/remote'));
        $env['PMSS_TEST_UMOUNT_FAIL'] = '1';
        // Neither recursive tool may run after the detach failure, even though
        // the pre-existing immutable action has its own best-effort fallback.
        foreach (['rm', 'find'] as $tool) {
            $this->pmssWriteExecutableFile($base.'/bin/'.$tool, "#!/bin/sh\ntouch \"\$PMSS_TEST_WALK\"\nexit 0\n");
        }
        $env['PMSS_TEST_WALK'] = $base.'/walked';
        foreach ([false, true] as $clear) {
            $this->assertSame(17, $this->pmssExecShellCommand($this->purgeCommand($home, $mountinfo, $clear), $env)['rc']);
            $this->assertFalse(file_exists($base.'/walked'));
            $this->assertSame('remote data', file_get_contents($home.'/remote/sentinel'));
        }
    }

    public function testDiscoveryFailuresForbidRecursionAndUnmounting(): void
    {
        [$base, $home, $mountinfo, $env] = $this->fixture();
        $snapshots = ['', $this->row($home.'/remote')."invalid\n"];
        // Noncanonical targets must refuse the whole snapshot before a queued detach.
        foreach (['/remote/../outside', '/remote/..', '/remote/./child', '/remote/.',
            '//remote', '/remote//child', '/remote/'] as $suffix) {
            $snapshots[] = $this->row($home.'/remote').$this->row($home.$suffix);
            $snapshots[] = $this->row($base.'/outside'.$suffix).$this->row($home.'/remote');
        }
        // A final fragment must stop both passes, with or without queued mounts.
        foreach (['invalid', '25', '25 1', '25 1 0:42 /',
            '25 1 0:42 / '.$home.'/remote', rtrim($this->row($home.'/remote'), "\n"), ' ', "\t"] as $tail) {
            foreach ([$home, $home.'/remote'] as $target) {
                $snapshots[] = $this->row($target).$tail;
            }
        }
        foreach ($snapshots as $contents) {
            $this->pmssWriteFile($mountinfo, $contents);
            foreach ([false, true] as $clear) {
                $this->assertTrue($this->pmssExecShellCommand($this->purgeCommand($home, $mountinfo, $clear), $env)['rc'] !== 0);
            }
        }
        unlink($mountinfo);
        $this->assertTrue($this->pmssExecShellCommand($this->purgeCommand($home, $mountinfo, false), $env)['rc'] !== 0);
        $this->assertFalse(file_exists($base.'/unmounted'));
        $this->assertSame('remote data', file_get_contents($home.'/remote/sentinel'));
    }

    public function testNoNestedMountsAndLinkedOrAbsentRootsDoNotUnmount(): void
    {
        [$base, $home, $mountinfo, $env] = $this->fixture();
        $this->pmssWriteFile($mountinfo, $this->row('/').$this->row($home));
        $command = 'sh -c '.escapeshellarg('cd '.escapeshellarg($home).' && '.$this->detachCommand($home, $mountinfo));
        $this->assertSame(0, $this->pmssExecShellCommand($command, $env)['rc']);
        $wrongRoot = 'sh -c '.escapeshellarg('cd '.escapeshellarg($base).' && '.$this->detachCommand($home, $mountinfo));
        $this->assertTrue($this->pmssExecShellCommand($wrongRoot, $env)['rc'] !== 0);
        rename($home, $base.'/saved');
        foreach ([false, true] as $clear) {
            $this->assertSame(0, $this->pmssExecShellCommand($this->purgeCommand($home, $mountinfo, $clear), $env)['rc']);
        }
        symlink($base.'/saved', $home);
        $this->assertSame(0, $this->pmssExecShellCommand($this->purgeCommand($home, $mountinfo, false), $env)['rc']);
        $this->assertFalse(file_exists($base.'/unmounted'));
        $this->assertSame('remote data', file_get_contents($base.'/saved/remote/sentinel'));
    }
}
