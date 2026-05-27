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

    public function testCommandBinaryNameIsSafeAcceptsSimpleBinaryNames(): void
    {
        $this->assertTrue(pmssCommandBinaryNameIsSafe('php'));
        $this->assertTrue(pmssCommandBinaryNameIsSafe('python3.11'));
        $this->assertTrue(pmssCommandBinaryNameIsSafe('seedbox-helper_test+1'));
    }

    public function testCommandBinaryNameIsSafeRejectsUnsafeNames(): void
    {
        $this->assertTrue(pmssCommandBinaryNameIsSafe('') === false);
        $this->assertTrue(pmssCommandBinaryNameIsSafe('two words') === false);
        $this->assertTrue(pmssCommandBinaryNameIsSafe('../php') === false);
        $this->assertTrue(pmssCommandBinaryNameIsSafe('php;id') === false);
        $this->assertTrue(pmssCommandBinaryNameIsSafe("php\nls") === false);
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

    public function testCommandPathReturnsEmptyStringWhenBinaryMissing(): void
    {
        putenv('PATH=/nonexistent');

        $this->assertEquals('', pmssCommandPath('pmss-missing-binary'));
    }

    public function testCommandPathReturnsEmptyStringForBlankInput(): void
    {
        $this->assertEquals('', pmssCommandPath('   '));
    }

    public function testCommandPathRejectsShellBuiltinsWithoutExecutablePaths(): void
    {
        $this->assertEquals('', pmssCommandPath('cd'));
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
