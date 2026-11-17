<?php
/** Guard iostat device arguments against option injection without running probes. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/diskIostat.php';

class DiskIostatDeviceArgumentSafetyTest extends TestCase
{
    public function testOptionShapedDevicesFailBeforeBinaryLookupOrQuoting(): void
    {
        $script = <<<'PHP'
namespace DiskIostatDeviceArgumentFixture;
class RuntimeException extends \RuntimeException {}
function pmssCommandPath($name) { $GLOBALS['events'][] = ['lookup', $name]; return '/fixture/iostat'; }
function escapeshellarg($value) { $GLOBALS['events'][] = ['quote', $value]; return \escapeshellarg($value); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/diskIostat/devices.php', 'DiskIostatDeviceArgumentFixture');
        $script .= <<<'PHP'
$results = [];
foreach (['-', '--', '--help', '-V', '-x', '-123', '-disk.name', '--disk_name'] as $device) {
    foreach (['', '/fixture/iostat'] as $binary) {
        // A valid prefix must not cause any command preparation before the bad entry is checked.
        foreach ([[$device], ['sda', $device], [$device, 'sda']] as $devices) {
            $GLOBALS['events'] = [];
            try {
                $result = pmssDiskIostatBuildCommand($devices, $binary);
            } catch (\RuntimeException $error) {
                $result = $error->getMessage();
            }
            $results[] = [pmssDiskIostatDeviceNameIsSafe($device), $result, $GLOBALS['events']];
        }
    }
}
echo json_encode($results);
PHP;
        $this->assertSame(array_fill(0, 48, [false, 'Unsafe block device name for iostat', []]),
            $this->pmssRunInlinePhpJson($script));
    }

    public function testValidDeviceArgumentsRetainTheirCommandBytes(): void
    {
        foreach (['sda', 'nvme0n1', 'dm-0', 'disk.name', 'disk_name', 'disk+name', '.disk', '+disk', '_disk'] as $device) {
            $this->assertTrue(\pmssDiskIostatDeviceNameIsSafe($device));
            $this->assertSame("'/fixture/io stat' -xm 120 2 -g grp1 '".$device."' 2>&1",
                \pmssDiskIostatBuildCommand([$device], '/fixture/io stat'));
        }
        // Empty discovery and multiple ordered devices keep the existing command framing.
        $this->assertSame("'/fixture/iostat' -xm 120 2 -g grp1 2>&1",
            \pmssDiskIostatBuildCommand([], '/fixture/iostat'));
        $this->assertSame("'/fixture/iostat' -xm 120 2 -g grp1 'dm-0' 'sda' 2>&1",
            \pmssDiskIostatBuildCommand(['dm-0', 'sda'], '/fixture/iostat'));
    }
}
