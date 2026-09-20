<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/runtime.php';

class RuntimeCommandPathTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssTrackEnvKeys(['PATH']);
    }

    public function testCommandBinaryNameSafetyMatrix(): void
    {
        foreach ([
            'php' => true,
            'python3.11' => true,
            'seedbox-helper_test+1' => true,
            '' => false,
            'two words' => false,
            '../php' => false,
            'php;id' => false,
            "php\nls" => false,
        ] as $binary => $expected) {
            $this->assertSame($expected, pmssCommandBinaryNameIsSafe($binary), 'Unexpected binary safety result for '.$binary);
        }
    }

    public function testCommandPathCandidateSafetyMatrix(): void
    {
        foreach ([
            '/usr/bin/php' => true,
            '/opt/pmss tools/bin/helper' => true,
            '' => false,
            'php' => false,
            " /usr/bin/php" => false,
            "/usr/bin/php\nwarning" => false,
            "/usr/bin/php\rbad" => false,
            "/usr/bin/php\0bad" => false,
        ] as $path => $expected) {
            $this->assertSame($expected, pmssCommandPathCandidateIsSafe($path), 'Unexpected command path safety result for '.str_replace("\0", '\\0', $path));
        }
    }

    public function testCommandPathValidatesRawLookupOutputBeforeTrimming(): void
    {
        // Inject only command lookup; real executable checks keep the boundary realistic.
        $runtime = var_export(dirname(__DIR__, 2).'/runtime.php', true);
        $environment = var_export(dirname(__DIR__, 2).'/runtime/environment.php', true);
        $script = <<<'PHP'
namespace CommandPathOutputFixture;
function shell_exec($command) {
    $GLOBALS['lookupCommands'][] = $command;
    return $GLOBALS['lookupOutput'];
}
PHP;
        $script .= "require {$runtime}; eval('namespace CommandPathOutputFixture;'.substr(file_get_contents({$environment}), 5));";
        $script .= <<<'PHP'
$path = PHP_BINARY;
$cases = [
    [null, ''], [false, ''], ['', ''], [" \t\n", ''],
    ["\0", ''], ["\0".$path, ''], [$path."\0", ''],
    [$path."\0\n", ''], [" \0".$path."\0 \n", ''],
    [$path."\0suffix", ''], ["/\0".ltrim($path, '/'), ''],
    [$path, $path], [$path."\n", $path], [" \t".$path."\r\n", $path],
    ['relative/path', ''], [$path."\nwarning", ''],
];
$results = [];
foreach ($cases as [$output, $expected]) {
    $GLOBALS['lookupOutput'] = $output;
    $GLOBALS['lookupCommands'] = [];
    $results[] = [pmssCommandPath(' php ') === $expected,
        $GLOBALS['lookupCommands'] === ["command -v 'php' 2>/dev/null"]];
}
echo json_encode($results);
PHP;
        $this->assertSame(array_fill(0, 16, [true, true]), $this->pmssRunInlinePhpJson($script));
    }

    public function testCommandPathRejectsResolvedPathsWithLineBreaks(): void
    {
        $binDir = $this->pmssMakeTempDir("pmss-command-path-newline-\n");
        $this->pmssWriteExecutableFile($binDir.'/pmss-newline-binary', "#!/bin/sh\nexit 0\n");
        $this->prependCommandPath($binDir);

        $this->assertEquals('', pmssCommandPath('pmss-newline-binary'));
    }

    public function testBlockDeviceNameIsDataDeviceMatchesBaseStorageDevicesOnly(): void
    {
        foreach (['sda', 'vda', 'xvda', 'nvme0n1', 'mmcblk0'] as $device) {
            $this->assertTrue(pmssBlockDeviceNameIsDataDevice($device), $device.' should match');
        }

        foreach (['sda1', 'nvme0n1p1', 'mmcblk0p1', 'loop0', 'md0', 'sd;bad', ''] as $device) {
            $this->assertTrue(!pmssBlockDeviceNameIsDataDevice($device), $device.' should not match');
        }
    }

    public function testCommandPathReturnsStubPathForSafeBinary(): void
    {
        $binDir = $this->pmssMakeExecutableStub('pmss-demo-binary', "#!/bin/sh\nexit 0\n", 'pmss-command-path-');
        $this->prependCommandPath($binDir);

        $this->assertEquals($binDir.'/pmss-demo-binary', pmssCommandPath('pmss-demo-binary'));
    }

    public function testCommandPathRejectsUnsafeBinaryNamesBeforeShellLookup(): void
    {
        $binDir = $this->pmssMakeExecutableStub('pmss-safe-binary', "#!/bin/sh\nexit 0\n", 'pmss-command-path-');
        $this->prependCommandPath($binDir);

        $this->assertEquals('', pmssCommandPath('../pmss-safe-binary'));
        $this->assertEquals('', pmssCommandPath('pmss-safe-binary;id'));
    }

    public function testCommandPathReturnsEmptyStringForUnavailableInputs(): void
    {
        putenv('PATH=/nonexistent');

        foreach (['pmss-missing-binary', '   ', 'cd'] as $binary) {
            $this->assertEquals('', pmssCommandPath($binary));
        }
    }

    public function testCommandPathRejectsNulBeforeTrimmingBinaryName(): void
    {
        $binary = 'pmss-safe-binary';
        $binDir = $this->pmssMakeExecutableStub($binary, "#!/bin/sh\nexit 0\n", 'pmss-command-path-nul-');
        $this->pmssWithPathPrefix($binDir, function () use ($binary, $binDir): void {
            $this->pmssAssertNoPhpWarnings(function () use ($binary, $binDir): void {
                foreach (["\0", "\0".$binary, $binary."\0", $binary."\0suffix", " \0".$binary."\0 \n"] as $input) {
                    $this->assertSame('', pmssCommandPath($input));
                }
                // Ordinary surrounding whitespace remains supported.
                foreach ([$binary, ' '.$binary.' ', "\t".$binary."\r\n"] as $input) {
                    $this->assertSame($binDir.'/'.$binary, pmssCommandPath($input));
                }
            });
        });
    }

    public function testIopingRejectsNulTargetsBeforeLaunchingProbeOrLock(): void
    {
        $binDir = $this->pmssMakeTempDir('pmss-ioping-nul-');
        $marker = $binDir.'/invoked';
        $stub = "#!/bin/sh\n: > ".escapeshellarg($marker)."\nexit 99\n";
        $this->pmssWriteExecutableFiles($binDir, ['ioping' => $stub, 'flock' => $stub]);

        $this->pmssWithPathPrefix($binDir, function () use ($marker): void {
            $this->pmssAssertNoPhpWarnings(function () use ($marker): void {
                foreach (['pmssIopingProbeOutput', 'pmssIopingAverageMs', 'pmssIopingMedianMs'] as $probe) {
                    foreach ([null, '', '  ', "\0", "\0/tmp", "/tmp\0", "/tmp\0suffix", " \0/tmp\0 "] as $target) {
                        $this->assertSame(null, $probe($target));
                    }
                }
                $this->assertFalse(file_exists($marker), 'Invalid targets must not launch ioping or flock');
            });
        });
    }

    public function testIopingPreservesValidTargetBytesAndResultShapes(): void
    {
        $binDir = $this->pmssMakeTempDir('pmss-ioping-target-');
        $argsLog = $binDir.'/args';
        $output = 'min/avg/max/mdev = 1.0 / 2.0 ms / 3.0 / 0.1';
        $this->pmssWriteExecutableFiles($binDir, [
            'ioping' => "#!/bin/sh\nprintf '%s\\000' \"\$@\" > ".escapeshellarg($argsLog)
                ."\nprintf '%s\\n' ".escapeshellarg($output)."\n",
            // Exercise the lock wrapper without opening a real runtime lock.
            'flock' => "#!/bin/sh\nshift 4\nexec \"\$@\"\n",
        ]);

        $this->pmssWithPathPrefix($binDir, function () use ($argsLog, $output): void {
            foreach (['/tmp', '/', 'relative', '/tmp/space name', "/tmp/quote'path", "/tmp/line\npath"] as $target) {
                foreach (['pmssIopingProbeOutput' => $output, 'pmssIopingAverageMs' => 2.0, 'pmssIopingMedianMs' => 2.0] as $probe => $expected) {
                    $this->assertSame($expected, $probe($target));
                    $this->assertSame(implode("\0", ['-c', '60', '-i', '0.1', '-D', $target, '']), file_get_contents($argsLog));
                }
            }
        });
    }

    public function testIopingAverageMsParsesReportedUnits(): void
    {
        foreach ([['1500 us', 1.5], ['2.75 ms', 2.75], ['0.25 s', 250.0]] as $case) {
            $binDir = $this->pmssMakeExecutableStub('ioping', "#!/bin/sh\nprintf '%s\\n' 'min/avg/max/mdev = 1.0 / ".$case[0]." / 3.0 / 0.1'\n", 'pmss-ioping-avg-');
            $this->pmssWithPathPrefix($binDir, function () use ($case): void {
                $this->assertEquals($case[1], pmssIopingAverageMs('/tmp'));
            });
        }
    }

    public function testIopingProbeUsesRepresentativeSamplingWindow(): void
    {
        $argsLog = $this->pmssMakeTempFile('pmss-ioping-args-');
        $binDir = $this->pmssMakeExecutableStub('ioping', "#!/bin/sh\nprintf '%s\\n' \"\$*\" > ".escapeshellarg($argsLog)."\nprintf '%s\\n' 'min/avg/max/mdev = 1.0 / 2.0 ms / 3.0 / 0.1'\n", 'pmss-ioping-args-');

        $this->pmssWithPathPrefix($binDir, function () use ($argsLog): void {
            $this->assertEquals(2.0, pmssIopingAverageMs('/tmp'));
            $this->assertSame("-c 60 -i 0.1 -D /tmp\n", (string) file_get_contents($argsLog));
        });
    }

    public function testIopingMedianMsUsesRequestSamples(): void
    {
        $binDir = $this->pmssMakeExecutableStub(
            'ioping',
            "#!/bin/sh\nprintf '%s\\n' '4 KiB <<< /tmp: request=1 time=1.0 ms'\nprintf '%s\\n' '4 KiB <<< /tmp: request=2 time=100.0 ms'\nprintf '%s\\n' '4 KiB <<< /tmp: request=3 time=3.0 ms'\nprintf '%s\\n' 'min/avg/max/mdev = 1.0 / 34.7 ms / 100.0 / 1.0'\n",
            'pmss-ioping-median-'
        );

        $this->pmssWithPathPrefix($binDir, function (): void {
            $this->assertEquals(3.0, pmssIopingMedianMs('/tmp'));
        });
    }

    public function testIopingMedianMsFallsBackToAverageSummary(): void
    {
        $binDir = $this->pmssMakeExecutableStub('ioping', "#!/bin/sh\nprintf '%s\\n' 'min/avg/max/mdev = 1.0 / 4.5 ms / 8.0 / 0.1'\n", 'pmss-ioping-median-');

        $this->pmssWithPathPrefix($binDir, function (): void {
            $this->assertEquals(4.5, pmssIopingMedianMs('/tmp'));
        });
    }

    public function testIopingAverageMsReturnsNullForMalformedOutput(): void
    {
        $binDir = $this->pmssMakeExecutableStub('ioping', "#!/bin/sh\nprintf '%s\\n' 'not ioping statistics'\n", 'pmss-ioping-avg-');
        $this->pmssWithPathPrefix($binDir, function (): void {
            $this->assertSame(null, pmssIopingAverageMs('/tmp'));
        });
    }

    public function testIopingAverageMsDoesNotInvokePathTail(): void
    {
        $binDir = $this->pmssMakeTempDir('pmss-ioping-tail-');
        $marker = $binDir.'/tail-invoked';
        $this->pmssWriteExecutableFiles($binDir, [
            'ioping' => "#!/bin/sh\nprintf '%s\\n' 'warmup line'\nprintf '%s\\n' 'min/avg/max/mdev = 1.0 / 4.5 ms / 3.0 / 0.1'\n",
            'tail' => "#!/bin/sh\n: > ".escapeshellarg($marker)."\nprintf '%s\\n' 'not ioping statistics'\n",
        ]);

        $this->pmssWithPathPrefix($binDir, function () use ($marker): void {
            $this->assertEquals(4.5, pmssIopingAverageMs('/tmp'));
            $this->assertFalse(file_exists($marker), 'PATH-provided tail must not be invoked');
        });
    }

    public function testIopingAverageMsSkipsEmptyTargetBeforeLaunching(): void
    {
        $binDir = $this->pmssMakeTempDir('pmss-ioping-empty-');
        $marker = $binDir.'/ioping-invoked';
        $this->pmssWriteExecutableFiles($binDir, [
            'ioping' => "#!/bin/sh\n: > ".escapeshellarg($marker)."\nprintf '%s\\n' 'min/avg/max/mdev = 1.0 / 4.5 ms / 3.0 / 0.1'\n",
        ]);

        $this->pmssWithPathPrefix($binDir, function () use ($marker): void {
            $this->assertSame(null, pmssIopingAverageMs(null));
            $this->assertSame(null, pmssIopingAverageMs('  '));
            $this->assertFalse(file_exists($marker), 'empty target must not launch ioping');
        });
    }

    public function testIopingAverageMsReturnsNullWhenIopingIsMissing(): void
    {
        $this->pmssWithEnv(['PATH' => '/nonexistent'], function (): void {
            $this->assertSame(null, pmssIopingAverageMs('/tmp'));
        });
    }

    private function prependCommandPath(string $binDir): void
    {
        $path = getenv('PATH');
        putenv('PATH='.$binDir.($path !== false ? ':'.$path : ''));
    }
}
