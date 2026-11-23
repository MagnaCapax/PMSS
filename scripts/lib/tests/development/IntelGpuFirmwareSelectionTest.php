<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/dpkgSelections.php';

/** Hardware-gated firmware selection and read-only PCI detection. */
class IntelGpuFirmwareSelectionTest extends TestCase
{
    public function testBothShippedBaselinesSelectAclOnEveryHost(): void
    {
        // setfacl applies the per-account render-node deny; Debian 13 always shipped acl, Debian 12 now matches.
        foreach ([12, 13] as $version) {
            $lines = file(dirname(__DIR__, 2).'/update/dpkg/selections-debian'.$version.'.txt', FILE_IGNORE_NEW_LINES);
            foreach ([false, true] as $intel) {
                $plan = \pmssDpkgSelectionsSanitise($lines, $version, ...self::pmssMakeCallbacks([true, $intel]));
                $this->assertTrue(in_array("acl\tinstall", $plan['sanitised'], true));
                $this->assertSame([], array_values(array_intersect(['acl'], $plan['dropped_hardware'])));
            }
        }
    }

    public function testFirmwareIsDroppedWithoutIntelDisplayButOtherPackagesRemain(): void
    {
        $plan = \pmssDpkgSelectionsSanitise(['alpha install', 'firmware-misc-nonfree install', 'beta hold'], 12, ...self::pmssMakeCallbacks([true, false]));
        $this->pmssAssertArraySubsetSame(['sanitised' => ["alpha\tinstall", "beta\thold"], 'dropped_hardware' => ['firmware-misc-nonfree']], $plan);
    }

    public function testFirmwareIsSelectedWithIntelDisplay(): void
    {
        $plan = \pmssDpkgSelectionsSanitise(['alpha install', 'firmware-misc-nonfree install'], 12, ...self::pmssMakeCallbacks([true, true]));
        $this->pmssAssertArraySubsetSame(['sanitised' => ["alpha\tinstall", "firmware-misc-nonfree\tinstall"], 'dropped_hardware' => []], $plan);
    }

    public function testIntelHostGetsNonFreeMediaDriverWhenAvailable(): void
    {
        $plan = \pmssDpkgSelectionsSanitise(['alpha install', 'intel-media-va-driver install'], 12, ...self::pmssMakeCallbacks([true, true]));
        $this->pmssAssertArraySubsetSame(['sanitised' => ["alpha\tinstall", "intel-media-va-driver-non-free\tinstall", "intel-media-va-driver\tdeinstall"]], $plan);
    }

    public function testArchQualifiedEntryKeepsItsSuffixOnBothLines(): void
    {
        $plan = \pmssDpkgSelectionsSanitise(["intel-media-va-driver:amd64\tinstall"], 12, ...self::pmssMakeCallbacks([true, true]));
        $this->pmssAssertArraySubsetSame(['sanitised' => ["intel-media-va-driver-non-free:amd64\tinstall", "intel-media-va-driver:amd64\tdeinstall"]], $plan);
    }

    public function testShippedDebian12BaselineSwapsTheMediaDriverOnIntelHosts(): void
    {
        $lines = file(dirname(__DIR__, 2).'/update/dpkg/selections-debian12.txt', FILE_IGNORE_NEW_LINES);
        $this->assertTrue(is_array($lines));
        $plan = \pmssDpkgSelectionsSanitise($lines, 12, ...self::pmssMakeCallbacks([true, true]));
        $this->assertTrue(in_array("intel-media-va-driver-non-free:amd64\tinstall", $plan['sanitised'], true));
        $this->assertTrue(in_array("intel-media-va-driver:amd64\tdeinstall", $plan['sanitised'], true));
        $this->assertFalse(in_array("intel-media-va-driver:amd64\tinstall", $plan['sanitised'], true));
    }

    public function testMediaDriverStaysFreeWithoutIntelDisplayOrWithoutNonFree(): void
    {
        $noIntel = \pmssDpkgSelectionsSanitise(['intel-media-va-driver install'], 12, ...self::pmssMakeCallbacks([true, false]));
        $this->pmssAssertArraySubsetSame(['sanitised' => ["intel-media-va-driver\tinstall"]], $noIntel);
        $noNonFree = \pmssDpkgSelectionsSanitise(['intel-media-va-driver install'], 12, function ($p) { return $p !== 'intel-media-va-driver-non-free'; }, ...self::pmssMakeCallbacks([true]));
        $this->pmssAssertArraySubsetSame(['sanitised' => ["intel-media-va-driver\tinstall"]], $noNonFree);
        $held = \pmssDpkgSelectionsSanitise(['intel-media-va-driver hold'], 12, ...self::pmssMakeCallbacks([true, true]));
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

    public function testAmdFirmwareFollowsAmdDisplayOnlyAndIntelFirmwareFollowsIntelOnly(): void
    {
        $lines = ['firmware-misc-nonfree install', 'firmware-amd-graphics install'];
        foreach ([
            ['0x1002', ["firmware-amd-graphics\tinstall"], ['firmware-misc-nonfree']],
            ['0x8086', ["firmware-misc-nonfree\tinstall"], ['firmware-amd-graphics']],
            ['none', [], ['firmware-misc-nonfree', 'firmware-amd-graphics']],
        ] as $case) {
            $present = function (string $vendor) use ($case): bool { return $vendor === $case[0]; };
            $plan = \pmssDpkgSelectionsSanitise($lines, 12, ...array_merge(self::pmssMakeCallbacks([true]), [$present]));
            $this->pmssAssertArraySubsetSame(['sanitised' => $case[1], 'dropped_hardware' => $case[2]], $plan);
        }
    }

    public function testShippedDebian12BaselineSelectsAmdFirmwareOnAmdHosts(): void
    {
        $lines = file(dirname(__DIR__, 2).'/update/dpkg/selections-debian12.txt', FILE_IGNORE_NEW_LINES);
        $amd = \pmssDpkgSelectionsSanitise($lines, 12, ...array_merge(self::pmssMakeCallbacks([true]), [function (string $v): bool { return $v === '0x1002'; }]));
        $this->assertTrue(in_array("firmware-amd-graphics\tinstall", $amd['sanitised'], true));
        $this->assertTrue(in_array("intel-media-va-driver:amd64\tinstall", $amd['sanitised'], true));
        $intel = \pmssDpkgSelectionsSanitise($lines, 12, ...self::pmssMakeCallbacks([true, false]));
        $this->assertTrue(in_array('firmware-amd-graphics', $intel['dropped_hardware'], true));
    }

    public function testRenderGpuDetectorAcceptsIntelAndAmdDisplayOnly(): void
    {
        $root = $this->pmssMakeTempDir('pmss-pci-render-gpu-', 0700);
        $this->assertFalse(\pmssHostHasRenderGpuDisplayPciDevice($root));
        foreach ([
            ['0x030000', '0x1a03', false, false],
            ['0x038000', '0x10de', false, false],
            ['0x040300', '0x1002', false, false],
            ['0x030000', '0x1002', true, false],
        ] as $index => $device) {
            $this->pmssWriteFiles($root.'/0000:00:0'.$index.'.0', ['class' => $device[0]."\n", 'vendor' => $device[1]."\n"]);
            $this->assertSame($device[2], \pmssHostHasRenderGpuDisplayPciDevice($root));
            $this->assertSame($device[3], \pmssHostHasIntelDisplayPciDevice($root));
            $this->assertSame($device[2], \pmssHostHasDisplayPciVendor('0x1002', $root));
        }
    }

    public function testIntelDisplayPciDetectorRejectsMissingVendor(): void
    {
        $root = $this->pmssMakeTempDir('pmss-pci-missing-vendor-', 0700);
        $this->pmssWriteFile($root.'/0000:00:02.0/class', "0x030000\n");
        $this->assertFalse(\pmssHostHasIntelDisplayPciDevice($root));
    }
    public function testOpenClRuntimeFollowsIntelDisplayOnly(): void
    {
        // Jellyfin OpenCL tone mapping needs the Intel ICD plus the IGC compiler; other hosts must never pull LLVM 14 for it.
        $lines = ["intel-opencl-icd:amd64\tinstall", "libigc1:amd64\tinstall", "libigdfcl1:amd64\tinstall", "alpha\tinstall"];
        $absent = \pmssDpkgSelectionsSanitise($lines, 12, ...self::pmssMakeCallbacks([true, false]));
        $this->pmssAssertArraySubsetSame(['sanitised' => ["alpha\tinstall"], 'dropped_hardware' => ['intel-opencl-icd:amd64', 'libigc1:amd64', 'libigdfcl1:amd64']], $absent);
        $present = \pmssDpkgSelectionsSanitise($lines, 12, ...self::pmssMakeCallbacks([true, true]));
        $this->pmssAssertArraySubsetSame(['sanitised' => ["intel-opencl-icd:amd64\tinstall", "libigc1:amd64\tinstall", "libigdfcl1:amd64\tinstall", "alpha\tinstall"], 'dropped_hardware' => []], $present);
    }

    public function testOpenClRuntimeHoldAndUnqualifiedNamesFollowTheSameGate(): void
    {
        // Only an install selection is gated: a hold stays as written, and the bare name is gated like the arch-qualified one.
        $held = \pmssDpkgSelectionsSanitise(['intel-opencl-icd hold'], 12, ...self::pmssMakeCallbacks([true, false]));
        $this->pmssAssertArraySubsetSame(['sanitised' => ["intel-opencl-icd\thold"], 'dropped_hardware' => []], $held);
        $bare = \pmssDpkgSelectionsSanitise(['libigc1 install', 'libigc12:amd64 install'], 12, ...self::pmssMakeCallbacks([true, false]));
        $this->pmssAssertArraySubsetSame(['sanitised' => ["libigc12:amd64\tinstall"], 'dropped_hardware' => ['libigc1']], $bare);
    }

    public function testShippedDebian12BaselineCarriesOpenClRuntimeOnlyForIntelHosts(): void
    {
        $lines = file(dirname(__DIR__, 2).'/update/dpkg/selections-debian12.txt', FILE_IGNORE_NEW_LINES);
        $this->assertTrue(is_array($lines));
        $packages = ['intel-opencl-icd:amd64', 'libigc1:amd64', 'libigdfcl1:amd64'];
        $intel = \pmssDpkgSelectionsSanitise($lines, 12, ...self::pmssMakeCallbacks([true, true]));
        $other = \pmssDpkgSelectionsSanitise($lines, 12, ...self::pmssMakeCallbacks([true, false]));
        foreach ($packages as $package) {
            $this->assertTrue(in_array($package."\tinstall", $intel['sanitised'], true));
            $this->assertFalse(in_array($package."\tinstall", $other['sanitised'], true));
            $this->assertTrue(in_array($package, $other['dropped_hardware'], true));
        }
    }
}
