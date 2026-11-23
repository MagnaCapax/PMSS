<?php
/** Host observation fixtures keep dpkg and free-space probes inside child fakes. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/kernelPin.php';

class KernelPinStateTest extends TestCase
{
    public function testInjectedPathsHoldAndDkmsRegistrations(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-kernel-state-', 0700);
        $this->pmssWriteFiles($dir, [
            'os-release' => "ID=debian\nVERSION_ID=11\nVERSION_CODENAME=bookworm\n",
            'hold' => "journal stall\nsecond line must not appear\n",
            'empty-hold' => '', 'not-a-directory' => '',
            'dkms/wireguard/source' => '', 'dkms/zfs/source' => '', 'dkms/dkms_dbversion' => '3',
            'wireguard-only/wireguard/source' => '',
        ]);
        $script = <<<'PHP'
namespace KernelPinStateFixture;
function pmssPackageStatus($name) { $GLOBALS['queries'][] = ['status', $name]; return 'install ok installed'; }
function pmssPackageVersion($name) { $GLOBALS['queries'][] = ['version', $name]; return \PMSS_KERNEL_PIN_VERSION; }
function disk_free_space($path) { $GLOBALS['spaces'][] = $path; return 2000 * 1048576; }
function function_exists($name) { return false; }
PHP;
        $result = $this->pmssRunInlinePhpJson($script
            .'require_once '.var_export($this->pmssRepoPath('scripts/lib/update/systemPrep/kernelPin.php'), true).';'
            .'function logmsg($message) {}'
            .\pmssTestingFunctionExport('pmssDetectDistro', 'KernelPinStateFixture')
            .\pmssTestingFunctionExport('pmssPinnedKernelState', 'KernelPinStateFixture')
            .'$GLOBALS["queries"] = $GLOBALS["spaces"] = []; $first = pmssPinnedKernelState();'
            .'putenv("PMSS_KERNEL_PIN_HOLD_PATH=".getenv("PMSS_KERNEL_PIN_BOOT_PATH")."/missing");'
            .'putenv("PMSS_KERNEL_PIN_DKMS_PATH=".getenv("PMSS_KERNEL_PIN_BOOT_PATH")."/missing");'
            .'$second = pmssPinnedKernelState();'
            .'putenv("PMSS_KERNEL_PIN_HOLD_PATH=".getenv("PMSS_KERNEL_PIN_BOOT_PATH")."/empty-hold");'
            .'putenv("PMSS_KERNEL_PIN_DKMS_PATH=".getenv("PMSS_KERNEL_PIN_BOOT_PATH")."/not-a-directory");'
            .'$third = pmssPinnedKernelState();'
            .'putenv("PMSS_KERNEL_PIN_ROOT_PATH=/");'
            .'putenv("PMSS_KERNEL_PIN_DKMS_PATH=".getenv("PMSS_KERNEL_PIN_BOOT_PATH")."/wireguard-only");'
            .'$fourth = pmssPinnedKernelState();'
            .'echo json_encode([$first, $second, $third, $fourth, $GLOBALS["spaces"], $GLOBALS["queries"]]);', [
                'PMSS_OS_RELEASE_PATH' => $dir.'/os-release', 'PMSS_KERNEL_PIN_BOOT_PATH' => $dir,
                'PMSS_KERNEL_PIN_ROOT_PATH' => $dir.'/root', 'PMSS_KERNEL_PIN_HOLD_PATH' => $dir.'/hold',
                'PMSS_KERNEL_PIN_DKMS_PATH' => $dir.'/dkms',
            ]);
        $this->assertSame(['name' => 'debian', 'version' => 12, 'codename' => 'bookworm'], $result[0]['distro']);
        $this->assertSame('journal stall', $result[0]['hold']);
        $this->assertSame(['wireguard', 'zfs'], $result[0]['dkms']);
        $this->assertSame(false, $result[0]['bpfPlanner']);
        $this->assertSame(PMSS_KERNEL_PIN_VERSION, $result[0]['version']);
        $this->assertSame('install ok installed', $result[0]['status']);
        $this->assertSame(null, $result[1]['hold']);
        $this->assertSame([], $result[1]['dkms']);
        $this->assertSame('', $result[2]['hold']);
        $this->assertSame(null, $result[2]['dkms']);
        $this->assertSame(['wireguard'], $result[3]['dkms']);
        $this->assertSame(array_merge(array_merge(...array_fill(0, 3, [$dir, $dir.'/root'])), [$dir, '/']), $result[4]);
        $this->assertSame(array_merge(...array_fill(0, 4, [
            ['status', PMSS_KERNEL_PIN_PACKAGE], ['version', PMSS_KERNEL_PIN_PACKAGE],
        ])), $result[5]);
    }

    public function testMissingBpfPlannerRefusesWithoutAnInstaller(): void
    {
        // Do not load systemPrep: absence of #1303 must prevent package installation.
        $script = <<<'PHP'
namespace KernelPinRefusalFixture;
function pmssPinnedKernelState() {
    return ['distro' => ['name' => 'debian', 'version' => 12], 'status' => '', 'version' => '',
        'hold' => null, 'dkms' => [], 'bootFree' => 2000 * 1048576, 'rootFree' => 2000 * 1048576,
        'bpfPlanner' => \function_exists('pmssBootDefaultsEnsureBpfLsm')];
}
function pmssInstallPinnedRemoteDebPackage(...$args) { throw new \RuntimeException('must not install'); }
PHP;
        $result = $this->pmssRunInlinePhpJson($script
            .'require_once '.var_export($this->pmssRepoPath('scripts/lib/update/systemPrep/kernelPin.php'), true).';'
            .\pmssTestingFunctionExport('pmssEnsurePinnedKernel', 'KernelPinRefusalFixture')
            .'$logs = []; pmssEnsurePinnedKernel(static function ($message) use (&$logs) { $logs[] = $message; });'
            .'echo json_encode($logs);');
        $this->assertSame(['[SKIP] Pinned kernel: refusal: #1303 BPF LSM planner pmssBootDefaultsEnsureBpfLsm is absent'], $result);
    }
}
