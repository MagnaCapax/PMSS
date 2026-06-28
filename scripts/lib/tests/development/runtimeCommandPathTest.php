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

    public function testIopingAverageMsParsesReportedUnits(): void
    {
        foreach ([['1500 us', 1.5], ['2.75 ms', 2.75], ['0.25 s', 250.0]] as $case) {
            $binDir = $this->pmssMakeExecutableStub('ioping', "#!/bin/sh\nprintf '%s\\n' 'min/avg/max/mdev = 1.0 / ".$case[0]." / 3.0 / 0.1'\n", 'pmss-ioping-avg-');
            $this->pmssWithPathPrefix($binDir, function () use ($case): void {
                $this->assertEquals($case[1], pmssIopingAverageMs('/tmp'));
            });
        }
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
