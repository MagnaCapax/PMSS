<?php
/** Verify storage-health mount selector guards without probing host filesystems. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth/space.php';

class StorageHealthSpaceInputSafetyTest extends TestCase
{
    private const STAT_OK = "4096 1000 200 100 1000 900\n";

    public function testInvalidSelectorsDoNotReachEitherProbe(): void
    {
        $calls = [];
        $capture = static function (string $command, int $timeout) use (&$calls): array {
            $calls[] = ['capture', $command, $timeout];
            return ['rc' => 0, 'stdout' => self::STAT_OK];
        };
        $deviceId = static function (string $path) use (&$calls): int {
            $calls[] = ['device', $path];
            return 1;
        };

        foreach (['', '-', '--help', '-f', "\0", "\0/fixture", "/fi\0xture", "/fixture\0",
            "/fixture\0suffix", null, false, true, 0, 1.5, []] as $mount) {
            $entries = \pmssStorageHealthSnapshotSpace('fixture-time', [$mount], $capture, $deviceId);
            $this->assertSame([[
                'timestamp' => 'fixture-time', 'kind' => 'fs', 'device' => $mount,
                'severity' => 'warn', 'flags' => ['space_unreadable'], 'metrics' => [],
            ]], $entries);
            $this->assertSame([], $calls);
        }
    }

    public function testValidSelectorsKeepExactCommandBytesTimeoutAndMetrics(): void
    {
        $commands = $devices = [];
        $capture = static function (string $command, int $timeout) use (&$commands): array {
            $commands[] = [$command, $timeout];
            return ['rc' => 0, 'stdout' => self::STAT_OK];
        };
        $deviceId = static function (string $path) use (&$devices): int {
            $devices[] = $path;
            return $path === '/home' ? 2 : 1;
        };
        $mounts = ['/', '/home', 'relative/mount', '0', '/fixture/mount space',
            "/fixture/mount'quote", '/fixture/-mount', './-mount'];

        $entries = \pmssStorageHealthSnapshotSpace('fixture-time', $mounts, $capture, $deviceId);

        $expectedCommands = [];
        foreach ($mounts as $index => $mount) {
            $expectedCommands[] = ["stat -f -c '%S %b %f %a %c %d' ".escapeshellarg($mount), 15];
            $this->assertSame([
                'timestamp' => 'fixture-time', 'kind' => 'fs', 'device' => $mount,
                'severity' => 'ok', 'flags' => [], 'metrics' => [
                    'total_bytes' => 3686400, 'free_bytes' => 409600,
                    'free_percent' => 11.1, 'inode_free_percent' => 90.0,
                ],
            ], $entries[$index]);
        }
        $this->assertSame($expectedCommands, $commands);
        $this->assertSame(['/home', '/'], $devices);
    }

    public function testRejectedSelectorDoesNotPreventLaterMountCollection(): void
    {
        $commands = [];
        $capture = static function (string $command, int $timeout) use (&$commands): array {
            $commands[] = $command;
            return ['rc' => 0, 'stdout' => self::STAT_OK];
        };

        $entries = \pmssStorageHealthSnapshotSpace('fixture-time', ['--help', '/', "/fixture\0", '/'], $capture);

        $this->assertSame(['warn', 'ok', 'warn', 'ok'], array_column($entries, 'severity'));
        $this->assertSame([['space_unreadable'], [], ['space_unreadable'], []], array_column($entries, 'flags'));
        $this->assertSame(array_fill(0, 2, "stat -f -c '%S %b %f %a %c %d' '/'"), $commands);
    }

    public function testValidSelectorsRetainUnreadableFallbackForProbeFailures(): void
    {
        foreach ([[1, self::STAT_OK], [124, self::STAT_OK], [0, 'garbage'], [0, '']] as [$rc, $stdout]) {
            $calls = [];
            $capture = static function (string $command, int $timeout) use ($rc, $stdout, &$calls): array {
                $calls[] = [$command, $timeout];
                return ['rc' => $rc, 'stdout' => $stdout];
            };
            $entries = \pmssStorageHealthSnapshotSpace('fixture-time', ['/'], $capture);
            $this->assertSame([[
                'timestamp' => 'fixture-time', 'kind' => 'fs', 'device' => '/',
                'severity' => 'warn', 'flags' => ['space_unreadable'], 'metrics' => [],
            ]], $entries);
            $this->assertSame([["stat -f -c '%S %b %f %a %c %d' '/'", 15]], $calls);
        }
    }
}
