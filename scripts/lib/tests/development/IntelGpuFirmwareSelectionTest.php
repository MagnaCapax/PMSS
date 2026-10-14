<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/dpkgSelections.php';

/** Hardware-gated firmware selection and read-only PCI detection. */
class IntelGpuFirmwareSelectionTest extends TestCase
{
    public function testFirmwareIsDroppedWithoutIntelDisplayButOtherPackagesRemain(): void
    {
        $available = static function (string $package): bool { return true; };
        $absent = static function (): bool { return false; };
        $plan = \pmssDpkgSelectionsSanitise(['alpha install', 'firmware-misc-nonfree install', 'beta hold'], 12, $available, $absent);
        $this->assertSame(["alpha\tinstall", "beta\thold"], $plan['sanitised']);
        $this->assertSame(['firmware-misc-nonfree'], $plan['dropped_hardware']);
    }

    public function testFirmwareIsSelectedWithIntelDisplay(): void
    {
        $available = static function (string $package): bool { return true; };
        $present = static function (): bool { return true; };
        $plan = \pmssDpkgSelectionsSanitise(['alpha install', 'firmware-misc-nonfree install'], 12, $available, $present);
        $this->assertSame(["alpha\tinstall", "firmware-misc-nonfree\tinstall"], $plan['sanitised']);
        $this->assertSame([], $plan['dropped_hardware']);
    }

    public function testIntelDisplayPciDetectorAcceptsOnlyMatchingClassAndVendor(): void
    {
        $root = $this->pmssMakeTempDir('pmss-pci-devices-', 0700);
        $this->assertFalse(\pmssHostHasIntelDisplayPciDevice($root.'/missing'));
        foreach ([
            ['0x030000', '0x1a03', false],
            ['0x060000', '0x8086', false],
            ['0x030000', '0x8086', true],
        ] as $index => $device) {
            $path = $root.'/0000:00:0'.$index.'.0';
            mkdir($path);
            file_put_contents($path.'/class', $device[0]."\n");
            file_put_contents($path.'/vendor', $device[1]."\n");
            $this->assertSame($device[2], \pmssHostHasIntelDisplayPciDevice($root));
        }
    }

    public function testIntelDisplayPciDetectorRejectsMissingVendor(): void
    {
        $root = $this->pmssMakeTempDir('pmss-pci-missing-vendor-', 0700);
        $device = $root.'/0000:00:02.0';
        mkdir($device);
        file_put_contents($device.'/class', "0x030000\n");
        $this->assertFalse(\pmssHostHasIntelDisplayPciDevice($root));
    }
}
