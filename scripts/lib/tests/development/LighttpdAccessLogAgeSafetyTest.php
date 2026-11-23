<?php
/** Verify access-log age evidence rejects corrupt lines and normalized invalid dates. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/accessLog.php';

class LighttpdAccessLogAgeSafetyTest extends TestCase
{
    private const NOW = 1800000000;
    private const CAP = 86400;

    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-access-log-age-']; }

    /** Exercise the bounded reader with a private stream positioned after its first line. */
    private function stale(string $line, int $mtime, ?int $cap = self::CAP, int $size = 128): bool
    {
        $handle = fopen('php://memory', 'w+');
        try {
            fwrite($handle, $line."\n");
            return \pmssLighttpdAccessLogIsStale($handle, ['size' => $size, 'mtime' => $mtime], $cap, self::NOW);
        } finally {
            fclose($handle);
        }
    }

    public function testInvalidDatesUseMtimeInsteadOfNormalizedTimestamps(): void
    {
        foreach ([
            '00/Jan/2020:00:00:00 +0000', '32/Jan/2020:00:00:00 +0000',
            '31/Apr/2020:00:00:00 +0000', '29/Feb/2021:00:00:00 +0000',
            '01/Jan/2020:25:00:00 +0000', '01/Jan/2020:00:60:00 +0000',
            '01/Jan/2020:00:00:60 +0000', '01/Bad/2020:00:00:00 +0000',
        ] as $stamp) {
            // Each apparent timestamp is old, but a recent mtime must preserve the log.
            $this->assertFalse($this->stale('['.$stamp.']', self::NOW), $stamp);
            $this->assertTrue($this->stale('['.$stamp.']', self::NOW - self::CAP - 1), $stamp);
        }
    }

    public function testCorruptAndMissingStampsKeepTheMtimeFallback(): void
    {
        foreach ([
            "\0[01/Jan/2020:00:00:00 +0000]", "[01/Jan/2020:00:00:00 +0000]\0",
            "[01/Jan/20\000:00:00:00 +0000]", "custom\0format", '', 'custom format',
        ] as $line) {
            $this->assertFalse($this->stale($line, self::NOW));
            $this->assertFalse($this->stale($line, self::NOW - self::CAP));
            $this->assertTrue($this->stale($line, self::NOW - self::CAP - 1));
        }
    }

    public function testValidStampsKeepOffsetsLeapDaysAndStrictAgeBoundary(): void
    {
        foreach ([
            '01/Jan/2026:00:00:00 +0000', '01/Jan/2026:02:00:00 +0200',
            '31/Dec/2025:19:00:00 -0500', '29/Feb/2024:00:00:00 +0000',
        ] as $stamp) {
            // An old valid entry still wins over a recent mtime.
            $this->assertTrue($this->stale('['.$stamp.']', self::NOW), $stamp);
        }
        $handle = fopen('php://memory', 'w+');
        try {
            fwrite($handle, "[01/Jan/2026:00:00:00 +0000]\n");
            $stat = ['size' => 128, 'mtime' => 1];
            $this->assertFalse(\pmssLighttpdAccessLogIsStale($handle, $stat, self::CAP, 1767225600 + self::CAP));
            $this->assertTrue(\pmssLighttpdAccessLogIsStale($handle, $stat, self::CAP, 1767225601 + self::CAP));
        } finally {
            fclose($handle);
        }
    }

    public function testDisabledCapsAndEmptyLogsNeverAuthorizeAgeTrim(): void
    {
        foreach ([null, 0, -1] as $cap) {
            $this->assertFalse($this->stale('[01/Jan/2020:00:00:00 +0000]', 1, $cap));
        }
        $this->assertFalse($this->stale('', 1, self::CAP, 0));
    }

    public function testTrimFilePreservesRecentMalformedLogAndKeepsFallbackAndSizeTrims(): void
    {
        $path = $this->tempDir.'/fixtur01/.lighttpd/access.log';
        $line = "[31/Apr/2020:00:00:00 +0000] fixture entry\n";
        foreach ([
            [self::NOW, 1024, ['status' => 'skip', 'reason' => 'below_threshold'], $line],
            [self::NOW - self::CAP - 1, 1024, ['status' => 'trimmed', 'reason' => 'age'], ''],
            [self::NOW, 1, ['status' => 'trimmed'], ''],
        ] as [$mtime, $threshold, $expected, $remaining]) {
            $this->pmssWriteFile($path, $line);
            touch($path, $mtime);
            clearstatcache(true, $path);
            $result = \pmssLighttpdAccessLogTrimFile($path, $threshold, self::CAP, self::NOW);
            $this->pmssAssertArraySubsetSame($expected, $result);
            $this->assertSame($remaining, file_get_contents($path));
            if ($threshold === 1) $this->assertFalse(isset($result['reason']));
        }
    }
}
