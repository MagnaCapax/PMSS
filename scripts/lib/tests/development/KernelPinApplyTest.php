<?php
/** Exercise delivery with intercepted state, installer and boot preparation. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/kernelPin.php';

class KernelPinApplyTest extends TestCase
{
    private function apply(array $scenario): array
    {
        $script = <<<'PHP'
namespace KernelPinApplyFixture;
function pmssPinnedKernelState() { return []; }
function pmssPinnedKernelPlan($state) {
    return ['action' => isset($GLOBALS['case']['skip']) ? 'skip' : 'install', 'reason' => $GLOBALS['case']['skip'] ?? ''];
}
function pmssResolvePathFromEnv($key, $default) { return $key === 'PMSS_KERNEL_PIN_BOOT_PATH' ? '/fixture/boot' : '/fixture/default/grub'; }
function pmssPinnedKernelGrubDefaultLog($path, $log) { $GLOBALS['events'][] = ['default', $path]; }
function pmssInstallPinnedRemoteDebPackage(...$args) { $GLOBALS['events'][] = ['install', $args]; return $GLOBALS['case']['install'] ?? true; }
function pmssEnvFlagEnabled($key) { return $GLOBALS['case']['dryRun'] ?? false; }
function pmssPinnedKernelBootPrepare(...$args) { $GLOBALS['events'][] = ['prepare', $args[0], $args[1]]; }
function is_file($path) { $GLOBALS['reads'][] = $path; return !in_array($path, $GLOBALS['case']['missing'] ?? [], true); }
function file_get_contents($path) { $GLOBALS['reads'][] = $path; return $GLOBALS['case']['menu'] ?? \PMSS_KERNEL_PIN_RELEASE; }
PHP;
        return $this->pmssRunInlinePhpJson($script
            .'require_once '.var_export($this->pmssRepoPath('scripts/lib/update/systemPrep/kernelPin.php'), true).';'
            .\pmssTestingFunctionExport('pmssEnsurePinnedKernel', 'KernelPinApplyFixture')
            .'$GLOBALS["case"] = '.var_export($scenario, true).'; $GLOBALS["events"] = $GLOBALS["reads"] = $logs = [];'
            .'pmssEnsurePinnedKernel(static function ($message) use (&$logs) { $logs[] = $message; });'
            .'echo json_encode([$GLOBALS["events"], $logs, $GLOBALS["reads"]]);');
    }

    public function testSkipsLogOnceAndNeverInstall(): void
    {
        foreach (['requires Debian 12', 'already installed', 'kernel-pin-hold: stop', 'DKMS module registered: zfs',
            '/boot free space 2 MiB; required 250 MiB', '/ free space 3 MiB; required 1300 MiB',
            'refusal: #1303 BPF LSM planner is absent'] as $reason) {
            $result = $this->apply(['skip' => $reason]);
            $this->assertSame([[], ['[SKIP] Pinned kernel: '.$reason], []], $result);
        }
    }

    public function testVerifiedPinIsPassedToInstallerAndArtifactsAreChecked(): void
    {
        $result = $this->apply([]);
        $this->assertSame([
            ['default', '/fixture/default/grub'],
            ['install', ['kernel '.PMSS_KERNEL_PIN_RELEASE, PMSS_KERNEL_PIN_URL, PMSS_KERNEL_PIN_SHA256]],
            ['prepare', '/fixture/boot', '/fixture/default/grub'],
        ], $result[0]);
        $this->assertSame([], $result[1]);
        $this->assertSame(['/fixture/boot/vmlinuz-'.PMSS_KERNEL_PIN_RELEASE,
            '/fixture/boot/initrd.img-'.PMSS_KERNEL_PIN_RELEASE, '/fixture/boot/grub/grub.cfg'], $result[2]);
    }

    public function testEachMissingBootArtifactWarns(): void
    {
        foreach (['vmlinuz-', 'initrd.img-'] as $prefix) {
            $path = '/fixture/boot/'.$prefix.PMSS_KERNEL_PIN_RELEASE;
            $result = $this->apply(['missing' => [$path]]);
            $this->assertSame(['[WARN] Pinned kernel missing '.$path], $result[1]);
        }
        foreach (['old-kernel-only', '', false] as $menu) {
            $result = $this->apply(['menu' => $menu]);
            $this->assertSame(['[WARN] Pinned kernel '.PMSS_KERNEL_PIN_RELEASE.' absent from /fixture/boot/grub/grub.cfg'], $result[1]);
        }
    }

    public function testFailedInstallAndDryRunNeverPrepareBoot(): void
    {
        foreach ([['install' => false], ['dryRun' => true]] as $scenario) {
            $result = $this->apply($scenario);
            $this->assertSame(2, count($result[0]));
            $this->assertSame([], $result[2]);
            $this->assertSame(isset($scenario['install'])
                ? ['[WARN] Pinned kernel install failed; continuing update without activation'] : [], $result[1]);
        }
    }
}
