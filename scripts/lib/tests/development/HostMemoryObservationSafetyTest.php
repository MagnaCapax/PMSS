<?php
/** Guard host-memory observations in scripts/lib/update/systemPrep/hostEnvironment.php. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/hostEnvironment.php';

class HostMemoryObservationSafetyTest extends TestCase
{
    public function testCorruptBalloonBytesUseZeroInsteadOfPartialEvidence(): void
    {
        $path = $this->pmssMakeTempFile('pmss-balloon-corrupt-');
        $this->pmssWithEnv(['PMSS_BALLOON_HELD_MIB' => null], function () use ($path): void {
            foreach ([
                "\0balloon_inflate 1024", "balloon_inflate 1024\0",
                "balloon_inflate 10\0".'24', "balloon_deflate 256\0",
                "balloon_deflate\0 256", "unrelated 1\0",
                'balloon_inflate '.PHP_INT_MAX.'0', 'balloon_deflate '.PHP_INT_MAX.'0',
            ] as $row) {
                // Reject the snapshot even when a valid earlier counter would supply a delta.
                $this->pmssWriteFile($path, "balloon_inflate 1024\n".$row."\n");
                $this->assertSame(0, \pmssBalloonHeldMiB($path));
            }
        });
    }

    public function testBalloonCountsRetainTruncationOrderingAndMissingCounterDefaults(): void
    {
        $path = $this->pmssMakeTempFile('pmss-balloon-valid-');
        $this->pmssWithEnv(['PMSS_BALLOON_HELD_MIB' => null], function () use ($path): void {
            foreach ([
                ['', 0], ["nr_free_pages 1\n", 0],
                ["balloon_inflate 0\nballoon_deflate 0\n", 0],
                ["balloon_inflate 255\n", 0], ["balloon_inflate 256\n", 1],
                ["balloon_deflate 1024\n", 0],
                ["balloon_inflate 1024\nballoon_deflate 256\n", 3],
                [" \tballoon_inflate\t0001025 \r\nballoon_deflate 000256\r\n", 3],
                ["balloon_inflate 256\nballoon_inflate 512\n", 2],
                ["balloon_inflate 256\nballoon_deflate 512\n", 0],
                ['balloon_inflate '.PHP_INT_MAX."\n", intdiv(PHP_INT_MAX, 256)],
                ['balloon_inflate '.PHP_INT_MAX."\nballoon_deflate ".PHP_INT_MAX."\n", 0],
            ] as [$raw, $expected]) {
                // Extreme integer counters must not overflow the pages-to-MiB conversion.
                $this->pmssWriteFile($path, $raw);
                $this->assertSame($expected, \pmssBalloonHeldMiB($path));
            }
        });
    }

    public function testInvalidMinFreeUsesTheExistingFloor(): void
    {
        $path = $this->pmssMakeTempFile('pmss-minfree-corrupt-');
        $this->pmssWithEnv(['PMSS_MIN_FREE_KBYTES' => null], function () use ($path): void {
            foreach ([
                "\0".'2097152', "2097152\0", "2097\0".'152', "2097152\n\0",
                (string) PHP_INT_MAX.'0', '0', '-1024', '1024.5', 'invalid', '', " \t\r\n",
            ] as $raw) {
                // In particular, trim() must not hide NUL bytes at the payload edges.
                $this->pmssWriteFile($path, $raw);
                $this->assertSame(1024, \pmssMinFreeMiB($path));
            }
        });
    }

    public function testValidMinFreeRetainsIntegerTruncationAndWhitespace(): void
    {
        $path = $this->pmssMakeTempFile('pmss-minfree-valid-');
        $this->pmssWithEnv(['PMSS_MIN_FREE_KBYTES' => null], function () use ($path): void {
            foreach ([
                ['1', 0], ['1023', 0], ['1024', 1], ['1025', 1],
                [" \t0002097152\r\n", 2048], ['1048576', 1024],
                [(string) PHP_INT_MAX, intdiv(PHP_INT_MAX, 1024)],
            ] as [$raw, $expected]) {
                $this->pmssWriteFile($path, $raw);
                $this->assertSame($expected, \pmssMinFreeMiB($path));
            }
        });
    }

    public function testMissingAndNulPathsRemainUnavailable(): void
    {
        $path = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-host-memory-').'/sample', '2097152');
        $this->pmssWithEnv(['PMSS_BALLOON_HELD_MIB' => null, 'PMSS_MIN_FREE_KBYTES' => null], function () use ($path): void {
            foreach ([$path.'-missing', "\0".$path, $path."\0", $path."\0suffix"] as $invalid) {
                $this->assertSame(0, \pmssBalloonHeldMiB($invalid));
                $this->assertSame(1024, \pmssMinFreeMiB($invalid));
            }
        });
    }

    public function testOverridesStillPrecedeUnavailableFilesIncludingZero(): void
    {
        foreach ([['8192', '2097152', 8192, 2048], ['0', '0', 0, 0]] as [$balloon, $minFree, $heldMiB, $freeMiB]) {
            $this->pmssWithEnv(['PMSS_BALLOON_HELD_MIB' => $balloon, 'PMSS_MIN_FREE_KBYTES' => $minFree],
                function () use ($heldMiB, $freeMiB): void {
                    // Invalid paths prove that explicit overrides bypass the file reads.
                    $this->assertSame($heldMiB, \pmssBalloonHeldMiB("\0"));
                    $this->assertSame($freeMiB, \pmssMinFreeMiB("\0"));
                });
        }
    }
}
