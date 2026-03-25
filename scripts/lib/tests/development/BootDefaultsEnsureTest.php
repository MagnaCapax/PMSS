<?php
namespace PMSS\Tests;
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class BootDefaultsEnsureTest extends TestCase
{
    private $prevDryRun;
    protected function setUp(): void { $this->prevDryRun = getenv('PMSS_DRY_RUN'); putenv('PMSS_DRY_RUN=1'); }
    protected function tearDown(): void { $this->prevDryRun === false || $this->prevDryRun === '' ? putenv('PMSS_DRY_RUN') : putenv('PMSS_DRY_RUN='.$this->prevDryRun); }
    public function testBootDefaultsMatrix(): void
    {
        $option = 'systemd.unified_cgroup_hierarchy=0';
        $logger = static function (string $message): void { };
        $cases = [
            ['label' => 'add-hidepid', 'fstab' => "proc /proc proc defaults 0 0\n", 'grub' => 'GRUB_CMDLINE_LINUX_DEFAULT="quiet '.$option.'"', 'expectFstab' => 'hidepid=2', 'expectGrub' => $option],
            ['label' => 'replace-hidepid', 'fstab' => "proc /proc proc defaults,hidepid=1 0 0\n", 'grub' => 'GRUB_CMDLINE_LINUX_DEFAULT="quiet '.$option.'"', 'expectFstab' => 'hidepid=2', 'rejectFstab' => 'hidepid=1'],
            ['label' => 'append-proc', 'fstab' => "UUID=abc /home ext4 defaults 0 0\n", 'grub' => 'GRUB_CMDLINE_LINUX_DEFAULT="quiet '.$option.'"', 'expectFstab' => "proc\t/proc\tproc\tdefaults,hidepid=2\t0\t0"],
            ['label' => 'append-option', 'fstab' => "proc /proc proc defaults,hidepid=2 0 0\n", 'grub' => 'GRUB_CMDLINE_LINUX_DEFAULT="quiet"', 'expectGrub' => $option],
            ['label' => 'add-grub-line', 'fstab' => "proc /proc proc defaults,hidepid=2 0 0\n", 'grub' => '# empty', 'expectGrub' => 'GRUB_CMDLINE_LINUX_DEFAULT="'.$option.'"'],
            ['label' => 'skip-grub', 'fstab' => "proc /proc proc defaults,hidepid=2 0 0\n", 'grub' => 'GRUB_CMDLINE_LINUX_DEFAULT="quiet '.$option.'"', 'grubUnchanged' => true],
        ];

        foreach ($cases as $case) {
            $dir = sys_get_temp_dir().'/pmss-boot-defaults-'.bin2hex(random_bytes(4)).'-'.$case['label'];
            mkdir($dir, 0700, true);
            $fstab = $dir.'/fstab'; $grub = $dir.'/grub';
            file_put_contents($fstab, $case['fstab']); file_put_contents($grub, $case['grub']."\n");
            $originalGrub = (string)file_get_contents($grub);
            \pmssEnsureBootDefaults($logger, $fstab, $grub, $option);
            $updatedFstab = (string)file_get_contents($fstab);
            if (isset($case['expectFstab'])) { $this->assertStringContainsString($case['expectFstab'], $updatedFstab, 'case '.$case['label'].' fstab'); }
            if (isset($case['rejectFstab'])) { $this->assertTrue(strpos($updatedFstab, $case['rejectFstab']) === false, 'case '.$case['label'].' fstab reject'); }
            $updatedGrub = (string)file_get_contents($grub);
            if (!empty($case['grubUnchanged'])) { $this->assertEquals($originalGrub, $updatedGrub, 'case '.$case['label'].' grub unchanged'); }
            elseif (isset($case['expectGrub'])) { $this->assertStringContainsString($case['expectGrub'], $updatedGrub, 'case '.$case['label'].' grub'); }
            $this->cleanup($dir);
        }
    }

    public function testBootDefaultsSupportsSerialConsoleSettings(): void
    {
        $logger = static function (string $message): void { };
        $dir = sys_get_temp_dir().'/pmss-boot-defaults-serial-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        $fstab = $dir.'/fstab';
        $grub = $dir.'/grub';
        file_put_contents($fstab, "proc /proc proc defaults,hidepid=2 0 0\n");
        file_put_contents($grub, "GRUB_CMDLINE_LINUX_DEFAULT=\"quiet\"\n");

        \pmssEnsureBootDefaults(
            $logger,
            $fstab,
            $grub,
            'systemd.unified_cgroup_hierarchy=0',
            ['console=tty0', 'console=ttyS0,115200n8'],
            [
                'GRUB_TERMINAL' => 'console serial',
                'GRUB_SERIAL_COMMAND' => 'serial --speed=115200 --unit=0 --word=8 --parity=no --stop=1',
            ]
        );

        $updatedGrub = (string) file_get_contents($grub);
        $this->assertStringContainsString('console=tty0', $updatedGrub);
        $this->assertStringContainsString('console=ttyS0,115200n8', $updatedGrub);
        $this->assertStringContainsString('GRUB_TERMINAL="console serial"', $updatedGrub);
        $this->assertStringContainsString('GRUB_SERIAL_COMMAND="serial --speed=115200 --unit=0 --word=8 --parity=no --stop=1"', $updatedGrub);

        $this->cleanup($dir);
    }

}
