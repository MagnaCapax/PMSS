<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Package and PCI input boundaries without invoking package tools or reading sysfs. */
class PackageStateInputSafetyTest extends TestCase
{
    public function testNulInputsNeverReachPackageOrFilesystemProbes(): void
    {
        $inputs = ["\0", "\0fixtur01", "fix\0tur01", "fixtur01\0", "fixtur01\0suffix"];
        $body = <<<'PHP'
$results = [];
foreach ($GLOBALS['state']['inputs'] as $input) {
    $results[] = [pmssPackageStatus($input), pmssPackageVersion($input),
        pmssPackageQueryField('fixtur01', $input), pmssPackageAvailable($input),
        pmssHostHasIntelDisplayPciDevice($input)];
}
PHP;
        $this->assertSame([array_fill(0, count($inputs), ['', '', '', false, false]), []],
            $this->runFixture($body, ['inputs' => $inputs]));
    }

    public function testFieldQueriesRetainCommandBytesAndFirstLineResults(): void
    {
        foreach ([
            ['fixtur01', 'Status', [" install ok installed \n", 'ignored'], 0, 'install ok installed'],
            ['fixtur01:amd64', 'Version', [' 1.2-3 ', 'ignored'], 0, '1.2-3'],
            ["fixtur01'quoted", 'Version', ['2'], 0, '2'],
            ['', 'Status', [], 0, ''],
            ['fixtur01', '', ['value'], 0, 'value'],
            ['fixtur01', 'Status', ['partial'], 1, ''],
        ] as [$package, $field, $output, $rc, $expected]) {
            $body = '$results = pmssPackageQueryField('.var_export($package, true).', '.var_export($field, true).');';
            $command = 'dpkg-query -W -f='.escapeshellarg('${'.$field.'}').' '.escapeshellarg($package).' 2>/dev/null';
            $this->assertSame([$expected, [['exec', $command]]],
                $this->runFixture($body, ['output' => $output, 'rc' => $rc]));
        }
    }

    public function testAvailabilityRetainsNameLookupCacheAndArchitectureHandling(): void
    {
        $body = <<<'PHP'
$results = [pmssPackageAvailable("fixtur01\0suffix"), pmssPackageAvailable('fixtur01'),
    pmssPackageAvailable('fixtur01'), pmssPackageAvailable('FIXTUR01:amd64'),
    pmssPackageAvailable('fixtur02'), pmssPackageAvailable("\0fixtur01")];
PHP;
        $this->assertSame([[false, true, true, true, false, false], [['exec', 'apt-cache pkgnames 2>/dev/null']]],
            $this->runFixture($body, ['names' => [' FIXTUR01 ', '']]));
    }

    public function testAvailabilityPolicyFallbackRetainsFailureResultsAndCache(): void
    {
        foreach ([
            [[], 0, false],
            [['Candidate: (none)'], 0, false],
            [['Candidate: 1.2-3'], 0, true],
            [['legacy output'], 0, true],
            [['Candidate: 1.2-3'], 1, false],
        ] as [$output, $rc, $expected]) {
            $body = '$results = [pmssPackageAvailable("fixtur01"), pmssPackageAvailable("fixtur01")];';
            $this->assertSame([[$expected, $expected], [
                ['exec', 'apt-cache pkgnames 2>/dev/null'],
                ['exec', "apt-cache policy 'fixtur01' 2>/dev/null"],
            ]], $this->runFixture($body, ['output' => $output, 'rc' => $rc]));
        }
    }

    public function testPciDetectorRetainsPathBytesAndMatchingPolicy(): void
    {
        $path = '/fixture/pci/0000:00:02.0/class';
        $vendorPath = dirname($path).'/vendor';
        foreach ([
            [" 0x030000\n", " 0x8086\n", true],
            ['0X030000', '0X8086', true],
            ['0x060000', '0x8086', false],
            ['0x030000', '0x1a03', false],
            [false, '0x8086', false],
            ['0x030000', false, false],
        ] as [$class, $vendor, $expected]) {
            $this->assertSame([$expected, [
                ['glob', '/fixture/pci/*/class'], ['read', $path], ['read', $vendorPath],
            ]], $this->runFixture('$results = pmssHostHasIntelDisplayPciDevice("/fixture/pci///");',
                ['paths' => [$path], 'files' => [$path => $class, $vendorPath => $vendor]]));
        }
        $this->assertSame([false, [['glob', '/fixture/missing/*/class']]],
            $this->runFixture('$results = pmssHostHasIntelDisplayPciDevice("/fixture/missing");'));
    }

    /** Intercept every external probe; each child starts with fresh static caches. */
    private function runFixture(string $body, array $state = []): array
    {
        $script = <<<'PHP'
namespace PackageStateInputFixture;
function exec($command, &$output, &$rc) {
    $GLOBALS['events'][] = ['exec', $command];
    $names = $command === 'apt-cache pkgnames 2>/dev/null';
    $output = $GLOBALS['state'][$names ? 'names' : 'output'] ?? [];
    $rc = $names ? 0 : ($GLOBALS['state']['rc'] ?? 0);
}
function glob($pattern) {
    $GLOBALS['events'][] = ['glob', $pattern];
    return $GLOBALS['state']['paths'] ?? [];
}
function file_get_contents($path) {
    $GLOBALS['events'][] = ['read', $path];
    return $GLOBALS['state']['files'][$path] ?? false;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/packageState.php', 'PackageStateInputFixture');
        $script .= '$GLOBALS["state"] = json_decode(getenv("PMSS_TEST_PACKAGE_STATE"), true); $GLOBALS["events"] = [];'
            .$body.'echo json_encode([$results, $GLOBALS["events"]]);';
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_PACKAGE_STATE' => json_encode($state)]);
    }
}
