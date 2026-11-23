<?php
/** Cover BPF-LSM convergence in scripts/lib/update/systemPrep/bootDefaultsGrub.php. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class BootDefaultsBpfLsmTest extends TestCase
{
    private const DEFAULTS = 'landlock,lockdown,yama,integrity,apparmor';
    private const ACTIVE = "lockdown,capability,landlock,yama,apparmor,ima,evm\n";

    public function testKernelPrerequisitesAndExistingParameters(): void
    {
        $config = 'CONFIG_BPF_LSM=y'."\n".'CONFIG_LSM="'.self::DEFAULTS.'"'."\n";
        $grub = ['GRUB_CMDLINE_LINUX_DEFAULT="quiet"'];
        $cases = [
            [self::ACTIVE, $config, $grub, self::DEFAULTS.',bpf'],
            ["capability,bpf\n", $config, $grub, null],
            ["capability,bpf,yama\n", $config, $grub, null],
            [self::ACTIVE, str_replace('CONFIG_BPF_LSM=y', '# CONFIG_BPF_LSM is not set', $config), $grub, null],
            [self::ACTIVE, str_replace('CONFIG_BPF_LSM=y', 'CONFIG_BPF_LSM=m', $config), $grub, null],
            [self::ACTIVE, str_replace('CONFIG_BPF_LSM=y', 'CONFIG_BPF_LSM=n', $config), $grub, null],
            [self::ACTIVE, 'CONFIG_BPF_LSM=y', $grub, null],
            [self::ACTIVE, str_replace(self::DEFAULTS, '', $config), $grub, null],
            [self::ACTIVE, str_replace(self::DEFAULTS, 'apparmor,$invalid', $config), $grub, null],
            [self::ACTIVE, $config."\0", $grub, null],
            [self::ACTIVE."\0", $config, $grub, null],
            ['', $config, $grub, null],
            [null, $config, $grub, null],
            [self::ACTIVE, null, $grub, null],
            [self::ACTIVE, $config, ['# no command line'], self::DEFAULTS.',bpf'],
            [self::ACTIVE, $config, ['GRUB_CMDLINE_LINUX_DEFAULT="quiet lsm=apparmor"'], 'apparmor,landlock,lockdown,yama,integrity,bpf'],
            [self::ACTIVE, $config, ['GRUB_CMDLINE_LINUX="lsm=selinux audit=1"'], 'selinux,'.self::DEFAULTS.',bpf'],
            [self::ACTIVE, $config, ['GRUB_CMDLINE_LINUX_DEFAULT=\'quiet lsm=apparmor,bpf\''], 'apparmor,bpf,landlock,lockdown,yama,integrity'],
            [self::ACTIVE, $config, ['GRUB_CMDLINE_LINUX_DEFAULT="security=selinux quiet"'], 'selinux,'.self::DEFAULTS.',bpf'],
            [self::ACTIVE, $config, ['GRUB_CMDLINE_LINUX_DEFAULT="security=apparmor security=selinux"'], 'selinux,apparmor,landlock,lockdown,yama,integrity,bpf'],
            [self::ACTIVE, $config, ['GRUB_CMDLINE_LINUX_DEFAULT="lsm=apparmor lsm=selinux"'], 'selinux,apparmor,landlock,lockdown,yama,integrity,bpf'],
            [self::ACTIVE, $config, ['GRUB_CMDLINE_LINUX_DEFAULT="lsm=selinux"', 'GRUB_CMDLINE_LINUX="lsm=apparmor audit=1"'], 'selinux,apparmor,landlock,lockdown,yama,integrity,bpf'],
            [self::ACTIVE, $config, ['GRUB_CMDLINE_LINUX_DEFAULT="lsm=$other"'], null],
            [self::ACTIVE, $config, ['GRUB_CMDLINE_LINUX_DEFAULT="lsm="'], null],
            [self::ACTIVE, $config, ['GRUB_CMDLINE_LINUX_DEFAULT=$other'], null],
            [self::ACTIVE, $config, ['GRUB_CMDLINE_LINUX_DEFAULT="$other quiet"'], null],
        ];
        foreach ($cases as $index => [$active, $kernel, $input, $expected]) {
            $dir = $this->pmssMakeTempDir('pmss-boot-bpf-', 0700);
            $files = [];
            if ($active !== null) $files['lsm'] = $active;
            if ($kernel !== null) $files['config'] = $kernel;
            $this->pmssWriteFiles($dir, $files);
            $lines = $input;
            $messages = [];
            $log = $this->pmssMakeArrayLogger($messages);
            $this->assertSame($expected !== null,
                \pmssBootDefaultsEnsureBpfLsm($lines, '/fixture/grub', $log, $dir.'/config', $dir.'/lsm'), 'case '.$index);
            if ($expected === null) {
                $this->assertSame($input, $lines, 'unchanged case '.$index);
                $this->assertSame([], $messages);
            } else {
                $this->assertStringContainsString('lsm='.$expected, implode("\n", $lines));
                $this->assertSame(1, substr_count(implode("\n", $lines), 'lsm='));
                $this->assertStringNotContainsString('capability', implode("\n", $lines));
                if (strpos(implode("\n", $input), 'audit=1') !== false) {
                    $this->assertStringContainsString('audit=1', implode("\n", $lines));
                }
                $this->assertFalse(\pmssBootDefaultsEnsureBpfLsm($lines, '/fixture/grub', $log, $dir.'/config', $dir.'/lsm'));
                $this->assertSame(1, count($messages), 'idempotent logging');
            }
        }
    }

    public function testBootDefaultsPersistsBeforeActivation(): void
    {
        // Export scripts/lib/update/systemPrep/bootDefaults.php; all host I/O is intercepted.
        $library = 'require_once '.var_export($this->pmssRepoPath('scripts/lib/update/systemPrep.php'), true).';'
            .\pmssTestingFunctionExport('pmssEnsureBootDefaults', 'BootBpfFixture')
            .\pmssTestingFunctionExport('pmssBootDefaultsEnsureBpfLsm', 'BootBpfFixture');
        foreach ([true, false] as $writeOk) {
            $result = $this->pmssRunInlinePhpJson(<<<'PHP'
namespace BootBpfFixture;
function pmssBootDefaultsEnsureProcHidepid($path, $log) {}
function is_readable($path) { return true; }
function file($path, $flags) { return ['GRUB_CMDLINE_LINUX_DEFAULT="quiet"']; }
function php_uname($mode) { return 'fixture-release'; }
function pmssResolvePathFromEnv($key, $default) { return $default; }
function file_get_contents($path) {
    $GLOBALS['reads'][] = $path;
    return $path === '/sys/kernel/security/lsm' ? 'capability,apparmor'
        : "CONFIG_BPF_LSM=y\nCONFIG_LSM=\"landlock,lockdown,yama,integrity,apparmor\"\n";
}
function pmssWriteManagedPathFileWithBackup($path, $lines, $kind, $log) {
    $GLOBALS['written'] = [$path, $lines, $kind]; return $GLOBALS['writeOk'];
}
function pmssBootDefaultsApplyGrubChanges($changed, $path, $option, $log) { $GLOBALS['activated'] = $changed; }
PHP
                .$library.'$GLOBALS["writeOk"] = '.var_export($writeOk, true).';'
                .'\BootBpfFixture\pmssEnsureBootDefaults(static function ($message) {});'
                .'echo json_encode([$GLOBALS["written"], $GLOBALS["activated"], $GLOBALS["reads"]]);');
            $this->assertSame(['/etc/default/grub', [
                'GRUB_CMDLINE_LINUX_DEFAULT="quiet systemd.unified_cgroup_hierarchy=0 lsm='.self::DEFAULTS.',bpf"',
            ], 'grub'], $result[0]);
            $this->assertSame($writeOk, $result[1]);
            $this->assertSame(['/sys/kernel/security/lsm', '/boot/config-fixture-release'], $result[2]);
        }
    }
}
