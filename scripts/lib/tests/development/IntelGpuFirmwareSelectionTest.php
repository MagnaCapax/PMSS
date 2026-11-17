<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/dpkgSelections.php';

/** Hardware-gated firmware selection and read-only PCI detection. */
class IntelGpuFirmwareSelectionTest extends TestCase
{
    public function testFirmwareIsDroppedWithoutIntelDisplayButOtherPackagesRemain(): void
    {
        $ops = self::pmssMakeCallbacks(['available' => true, 'intelDisplay' => false]);
        $plan = \pmssDpkgSelectionsSanitise(['alpha install', 'firmware-misc-nonfree install', 'beta hold'], 12, $ops['available'], $ops['intelDisplay']);
        $this->pmssAssertArraySubsetSame(['sanitised' => ["alpha\tinstall", "beta\thold"], 'dropped_hardware' => ['firmware-misc-nonfree']], $plan);
    }

    public function testFirmwareIsSelectedWithIntelDisplay(): void
    {
        $ops = self::pmssMakeCallbacks(['available' => true, 'intelDisplay' => true]);
        $plan = \pmssDpkgSelectionsSanitise(['alpha install', 'firmware-misc-nonfree install'], 12, $ops['available'], $ops['intelDisplay']);
        $this->pmssAssertArraySubsetSame(['sanitised' => ["alpha\tinstall", "firmware-misc-nonfree\tinstall"], 'dropped_hardware' => []], $plan);
    }

    public function testIntelHostGetsNonFreeMediaDriverWhenAvailable(): void
    {
        $plan = \pmssDpkgSelectionsSanitise(['alpha install', 'intel-media-va-driver install'], 12, function () { return true; }, function () { return true; });
        $this->pmssAssertArraySubsetSame(['sanitised' => ["alpha\tinstall", "intel-media-va-driver-non-free\tinstall", "intel-media-va-driver\tdeinstall"]], $plan);
    }

    public function testArchQualifiedEntryKeepsItsSuffixOnBothLines(): void
    {
        $plan = \pmssDpkgSelectionsSanitise(["intel-media-va-driver:amd64\tinstall"], 12, function () { return true; }, function () { return true; });
        $this->pmssAssertArraySubsetSame(['sanitised' => ["intel-media-va-driver-non-free:amd64\tinstall", "intel-media-va-driver:amd64\tdeinstall"]], $plan);
    }

    public function testShippedDebian12BaselineSwapsTheMediaDriverOnIntelHosts(): void
    {
        $lines = file(dirname(__DIR__, 2).'/update/dpkg/selections-debian12.txt', FILE_IGNORE_NEW_LINES);
        $this->assertTrue(is_array($lines));
        $plan = \pmssDpkgSelectionsSanitise($lines, 12, function () { return true; }, function () { return true; });
        $this->assertTrue(in_array("intel-media-va-driver-non-free:amd64\tinstall", $plan['sanitised'], true));
        $this->assertTrue(in_array("intel-media-va-driver:amd64\tdeinstall", $plan['sanitised'], true));
        $this->assertFalse(in_array("intel-media-va-driver:amd64\tinstall", $plan['sanitised'], true));
    }

    public function testMediaDriverStaysFreeWithoutIntelDisplayOrWithoutNonFree(): void
    {
        $noIntel = \pmssDpkgSelectionsSanitise(['intel-media-va-driver install'], 12, function () { return true; }, function () { return false; });
        $this->pmssAssertArraySubsetSame(['sanitised' => ["intel-media-va-driver\tinstall"]], $noIntel);
        $noNonFree = \pmssDpkgSelectionsSanitise(['intel-media-va-driver install'], 12, function ($p) { return $p !== 'intel-media-va-driver-non-free'; }, function () { return true; });
        $this->pmssAssertArraySubsetSame(['sanitised' => ["intel-media-va-driver\tinstall"]], $noNonFree);
        $held = \pmssDpkgSelectionsSanitise(['intel-media-va-driver hold'], 12, function () { return true; }, function () { return true; });
        $this->pmssAssertArraySubsetSame(['sanitised' => ["intel-media-va-driver\thold"]], $held);
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
            $this->pmssWriteFiles($root.'/0000:00:0'.$index.'.0', ['class' => $device[0]."\n", 'vendor' => $device[1]."\n"]);
            $this->assertSame($device[2], \pmssHostHasIntelDisplayPciDevice($root));
        }
    }

    public function testIntelDisplayPciDetectorRejectsMissingVendor(): void
    {
        $root = $this->pmssMakeTempDir('pmss-pci-missing-vendor-', 0700);
        $this->pmssWriteFile($root.'/0000:00:02.0/class', "0x030000\n");
        $this->assertFalse(\pmssHostHasIntelDisplayPciDevice($root));
    }
}
