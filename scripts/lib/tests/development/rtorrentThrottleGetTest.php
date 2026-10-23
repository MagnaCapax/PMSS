<?php
/** Tests for the read-only live rTorrent throttle CLI. */
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/util/rtorrentThrottleGet.php';

class RtorrentThrottleGetTest extends TestCase
{
    public function testReadsBothLiveLimitsAndPreservesUnlimitedZero(): void
    {
        $calls = [];
        [$exitCode, $output] = $this->pmssCaptureStdout(static function () use (&$calls): int {
            return \pmssRtorrentThrottleGetCli(['script', 'alice'], static function ($socket, $method) use (&$calls) {
                $calls[] = [$socket, $method];
                return strpos($method, 'global_up') !== false ? 0 : 2048;
            });
        });

        $this->assertSame(0, $exitCode);
        $this->assertSame("upload_bytes_per_second=0\ndownload_bytes_per_second=2048\n", $output);
        $this->assertSame([
            ['/home/alice/.rtorrent.socket', 'throttle.global_up.max_rate'],
            ['/home/alice/.rtorrent.socket', 'throttle.global_down.max_rate'],
        ], $calls);
    }

    public function testFailedOrMalformedReadsNeverEmitPartialOutput(): void
    {
        [$firstFailure, $output] = $this->pmssCaptureStdout(static function (): int {
            return \pmssRtorrentThrottleGetCli(['script', 'alice'], static function () {
                return false;
            });
        });
        $this->assertSame(2, $firstFailure);
        $this->assertSame('', $output);

        foreach ([false, -1, '2048', null] as $badRate) {
            $calls = 0;
            [$exitCode, $output] = $this->pmssCaptureStdout(static function () use (&$calls, $badRate): int {
                return \pmssRtorrentThrottleGetCli(['script', 'alice'], static function () use (&$calls, $badRate) {
                    return ++$calls === 1 ? 1024 : $badRate;
                });
            });

            $this->assertSame(2, $exitCode);
            $this->assertSame('', $output);
        }
    }

    public function testInvalidUsernameNeverCallsScgi(): void
    {
        foreach ([[], ['script'], ['script', '../root'], ['script', 'ALICE'], ['script', 'alice', 'extra']] as $argv) {
            $calls = 0;
            [$exitCode, $output] = $this->pmssCaptureStdout(static function () use ($argv, &$calls): int {
                return \pmssRtorrentThrottleGetCli($argv, static function () use (&$calls) {
                    $calls++;
                    return 0;
                });
            });

            $this->assertSame(1, $exitCode);
            $this->assertSame(0, $calls);
            $this->assertSame('', $output);
        }
    }
}
