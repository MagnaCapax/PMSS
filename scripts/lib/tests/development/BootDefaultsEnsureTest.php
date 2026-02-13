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

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) { return; }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
        @rmdir($path);
    }
}
