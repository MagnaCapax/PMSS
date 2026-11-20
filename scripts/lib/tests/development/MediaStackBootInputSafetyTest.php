<?php
/** Verify media-stack boot observation boundaries without reading host proc files. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class MediaStackBootInputSafetyTest extends TestCase
{
    /** Intercept every reader call while loading the real scripts/lib/mediaStackBootRelaunch.php. */
    private function observe(array $cases): array
    {
        $script = <<<'PHP'
namespace MediaStackBootInputFixture;
function file_get_contents($path) {
    $GLOBALS['reads'][] = $path;
    return $GLOBALS['raw'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace(
            'scripts/lib/mediaStackBootRelaunch.php', 'MediaStackBootInputFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_MEDIA_STACK_BOOT_INPUT'), true) as [$reader, $path, $raw]) {
    $GLOBALS['reads'] = []; $GLOBALS['raw'] = $raw;
    $function = __NAMESPACE__.'\\'.$reader;
    $result = $path === null ? $function() : $function($path);
    $results[] = [$result, $GLOBALS['reads']];
}
echo json_encode($results, JSON_PRESERVE_ZERO_FRACTION);
PHP;
        return $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_MEDIA_STACK_BOOT_INPUT' => json_encode($cases),
        ]);
    }

    public function testMalformedPathsNeverReachFileIo(): void
    {
        $cases = $expected = [];
        foreach (['pmssMediaStackBootIdRead' => '', 'pmssMediaStackBootUptimeRead' => 0.0] as $reader => $fallback) {
            foreach (['', "\0", "\0/fixture/proc", "/fixture/\0proc", "/fixture/proc\0", "/fixture/proc\0suffix"] as $path) {
                $cases[] = [$reader, $path, '12345678'];
                $expected[] = [$fallback, []];
            }
        }
        $this->assertSame($expected, $this->observe($cases));
    }

    public function testNulObservationsCannotBeNormalizedIntoBootEvidence(): void
    {
        $cases = $expected = [];
        foreach (['pmssMediaStackBootIdRead' => ['abcdef12', ''],
            'pmssMediaStackBootUptimeRead' => ['12345.67 98765.43', 0.0]] as $reader => [$valid, $fallback]) {
            // Include bytes trim() would erase and corruption outside the uptime field.
            foreach (["\0".$valid, $valid."\0", $valid."\n\0", "\t\0".$valid,
                substr_replace($valid, "\0", 3, 0), $valid." other\0data"] as $raw) {
                $cases[] = [$reader, '/fixture/proc', $raw];
                $expected[] = [$fallback, ['/fixture/proc']];
            }
        }
        $this->assertSame($expected, $this->observe($cases));
    }

    public function testUnavailableAndMalformedReadingsKeepTheirFallbacks(): void
    {
        $cases = $expected = [];
        foreach (['pmssMediaStackBootIdRead' => ['', [false, null, [], '', 'short', 'ABCDEF12', str_repeat('a', 65), 'not a boot id']],
            'pmssMediaStackBootUptimeRead' => [0.0, [false, null, [], '', 'soon', '-1 5', '0 5', '1e999 5', '-1e999 5', 'INF 5', 'NAN 5']]] as $reader => [$fallback, $samples]) {
            foreach ($samples as $raw) {
                $cases[] = [$reader, '/fixture/proc', $raw];
                $expected[] = [$fallback, ['/fixture/proc']];
            }
        }
        $this->assertSame($expected, $this->observe($cases));
    }

    public function testValidBootIdsKeepLegacyPatternAndWhitespace(): void
    {
        $cases = $expected = [];
        // Preserve the existing length/pattern policy rather than tightening it to UUIDs.
        foreach (['abcdef12', str_repeat('a', 64), '--------', '6f1e2a3c-0a4b-4c5d-8e9f-a1b2c3d4e5f6',
            " \tabcdef12\r\n"] as $raw) {
            $cases[] = ['pmssMediaStackBootIdRead', "/fixture/proc's boot_id", $raw];
            $expected[] = [trim($raw), ["/fixture/proc's boot_id"]];
        }
        $this->assertSame($expected, $this->observe($cases));
    }

    public function testFiniteUptimeKeepsLegacyNumericAndFirstFieldHandling(): void
    {
        $cases = $expected = [];
        foreach ([['12345.67 98765.43', 12345.67], ['1', 1.0], ['0.25 5', 0.25],
            ['1e3 5', 1000.0], ['+2.5 5', 2.5], [" \t123.5 90\r\n", 123.5]] as [$raw, $value]) {
            $cases[] = ['pmssMediaStackBootUptimeRead', '/fixture/uptime', $raw];
            $expected[] = [$value, ['/fixture/uptime']];
        }
        $this->assertSame($expected, $this->observe($cases));
    }

    public function testDefaultPathsStillReadExactlyOnce(): void
    {
        $this->assertSame([
            ['abcdef12', ['/proc/sys/kernel/random/boot_id']],
            [123.5, ['/proc/uptime']],
        ], $this->observe([
            ['pmssMediaStackBootIdRead', null, 'abcdef12'],
            ['pmssMediaStackBootUptimeRead', null, '123.5 90'],
        ]));
    }
}
