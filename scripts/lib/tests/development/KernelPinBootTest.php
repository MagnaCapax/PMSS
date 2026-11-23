<?php
/** Target-kernel boot defaults preserve activation pins and the managed-write gate. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class KernelPinBootTest extends TestCase
{
    private const DEFAULTS = 'landlock,lockdown,yama,integrity,apparmor';

    public function testGrubDefaultPinIsLoggedAndRetainedByteForByte(): void
    {
        foreach (['0', '"0"', "'0'", 'saved', '"1>2"', "'older kernel'"] as $value) {
            $dir = $this->pmssMakeTempDir('pmss-kernel-default-', 0700);
            $bytes = "# activation selection\nGRUB_DEFAULT=".$value."\nGRUB_CMDLINE_LINUX_DEFAULT=\"quiet\"\n";
            $grub = $this->pmssWriteFile($dir.'/grub', $bytes);
            $messages = $this->pmssArrayLoggerMessages(static function ($log) use ($grub): void {
                \pmssPinnedKernelGrubDefaultLog($grub, $log);
            });
            $this->assertSame($bytes, file_get_contents($grub));
            $isDefault = in_array($value, ['0', '"0"', "'0'"], true);
            $this->assertSame($isDefault ? [] : [
                '[WARN] Pinned kernel activation: GRUB_DEFAULT='.$value.'; host may not boot the new kernel automatically',
            ], $messages);
        }
    }

    public function testTargetKernelPreparationUsesManagedWriteBeforeGrubRegeneration(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-kernel-boot-', 0700);
        $this->pmssWriteFiles($dir, [
            'config-'.PMSS_KERNEL_PIN_RELEASE => 'CONFIG_BPF_LSM=y'."\nCONFIG_LSM=\"".self::DEFAULTS."\"\n",
            'grub' => "GRUB_DEFAULT='1>2'\nGRUB_CMDLINE_LINUX_DEFAULT=\"quiet\"\n",
        ]);
        $script = <<<'PHP'
namespace KernelPinBootFixture;
function pmssWriteManagedPathFileWithBackup($path, $lines, $kind, $log) {
    $GLOBALS['events'][] = ['write', $path, $lines, $kind]; return $GLOBALS['writeOk'];
}
function pmssBootDefaultsApplyGrubChanges($changed, $path, $option, $log) {
    if ($changed) $GLOBALS['events'][] = ['update-grub', $path, $option];
}
PHP;
        foreach ([true, false] as $writeOk) {
            $result = $this->pmssRunInlinePhpJson($script
                .'require_once '.var_export($this->pmssRepoPath('scripts/lib/update/systemPrep.php'), true).';'
                .\pmssTestingFunctionExport('pmssPinnedKernelBootPrepare', 'KernelPinBootFixture')
                .'$GLOBALS["events"] = []; $GLOBALS["writeOk"] = '.var_export($writeOk, true).';'
                .'pmssPinnedKernelBootPrepare('.var_export($dir, true).', '.var_export($dir.'/grub', true).', static function ($message) {});'
                .'echo json_encode($GLOBALS["events"]);');
            $this->assertSame(['write', $dir.'/grub', ["GRUB_DEFAULT='1>2'",
                'GRUB_CMDLINE_LINUX_DEFAULT="quiet lsm='.self::DEFAULTS.',bpf"'], 'grub'], $result[0]);
            $this->assertSame($writeOk ? 2 : 1, count($result));
            if ($writeOk) $this->assertSame(['update-grub', $dir.'/grub', ''], $result[1]);
        }
    }

    public function testMissingInputsOnlyWarn(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-kernel-boot-missing-', 0700);
        $messages = $this->pmssArrayLoggerMessages(static function ($log) use ($dir): void {
            \pmssPinnedKernelBootPrepare($dir, $dir.'/grub', $log);
        });
        $this->pmssAssertMessagesContain($messages, 'config not readable');
        $this->pmssWriteFile($dir.'/config-'.PMSS_KERNEL_PIN_RELEASE, 'CONFIG_BPF_LSM=y');
        $messages = $this->pmssArrayLoggerMessages(static function ($log) use ($dir): void {
            \pmssPinnedKernelBootPrepare($dir, $dir.'/grub', $log);
        });
        $this->pmssAssertMessagesContain($messages, 'grub not readable');
    }

    public function testDeclinedPreparationWarnsUnlessBpfIsAlreadyPinned(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-kernel-boot-declined-', 0700);
        $this->pmssWriteFile($dir.'/config-'.PMSS_KERNEL_PIN_RELEASE, 'CONFIG_BPF_LSM=y'."\nCONFIG_LSM=\"".self::DEFAULTS."\"\n");
        $cases = [
            // A shell expression the planner will not rewrite: install went ahead, so the gap must be visible.
            'GRUB_CMDLINE_LINUX_DEFAULT="quiet $EXTRA"' => true,
            // Already pinned with bpf: nothing to do and nothing to warn about.
            'GRUB_CMDLINE_LINUX_DEFAULT="quiet lsm='.self::DEFAULTS.',bpf"' => false,
        ];
        foreach ($cases as $cmdline => $expectWarn) {
            $bytes = "GRUB_DEFAULT=0\n".$cmdline."\n";
            $grub = $this->pmssWriteFile($dir.'/grub', $bytes);
            $messages = $this->pmssArrayLoggerMessages(static function ($log) use ($dir, $grub): void {
                \pmssPinnedKernelBootPrepare($dir, $grub, $log);
            });
            $this->assertSame($bytes, file_get_contents($grub));
            $warned = false;
            foreach ($messages as $message) $warned = $warned || strpos($message, 'first-boot BPF LSM not prepared') !== false;
            $this->assertSame($expectWarn, $warned, $cmdline);
        }
    }
}
