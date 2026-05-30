<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cgroup/RealSystem.php';

class cgroupRealSystemProbe extends \PMSS\Cgroup\RealSystem
{
    /** @var string */
    public $lastCommand = '';
    /** @var string */
    public $returnValue = '';

    public function execute(string $command): ?string
    {
        $this->lastCommand = $command;
        return $this->returnValue;
    }
}

class cgroupRealSystemTest extends TestCase
{
    public function testExecuteReturnsEmptyStringInTestMode(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DEVICE' => null]);
        $sys = new \PMSS\Cgroup\RealSystem();
        $this->assertEquals('', $sys->execute('echo test'));
    }

    public function testResolveDeviceUsesHomeOverride(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DEVICE' => '/dev/md-test']);
        $probe = new cgroupRealSystemProbe();

        $resolved = $probe->resolveDevice('/home');

        $this->assertEquals('/dev/md-test', $resolved);
        $this->assertEquals('', $probe->lastCommand);
    }

    public function testResolveDeviceQueriesHomeWithoutOverride(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DEVICE' => null]);
        $probe = new cgroupRealSystemProbe();
        $probe->returnValue = '/dev/md0';

        $resolved = $probe->resolveDevice('/home');

        $this->assertEquals('/dev/md0', $resolved);
        $this->assertEquals('findmnt -no SOURCE /home 2>/dev/null', $probe->lastCommand);
    }

    public function testResolveDeviceEscapesArbitraryPath(): void
    {
        $probe = new cgroupRealSystemProbe();
        $probe->returnValue = '/dev/sdb1';

        $resolved = $probe->resolveDevice('/mnt/data set');

        $this->assertEquals('/dev/sdb1', $resolved);
        $this->assertEquals("findmnt -no SOURCE '/mnt/data set' 2>/dev/null", $probe->lastCommand);
    }

    public function testGetUidReturnsMinusOneForMissingUser(): void
    {
        $sys = new \PMSS\Cgroup\RealSystem();
        $this->assertEquals(-1, $sys->getUid('pmss-this-user-should-not-exist-xyz'));
    }
}
