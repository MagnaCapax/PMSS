<?php
namespace PMSS\Tests;
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';
require_once dirname(__DIR__, 2).'/update/services/mountHardening.php';
require_once dirname(__DIR__, 2).'/update/services/quota.php';

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
            $dir = $this->pmssMakeTempDir('pmss-boot-defaults-'.$case['label'].'-', 0700);
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
        }
    }

    public function testBootDefaultsSupportsSerialConsoleSettings(): void
    {
        $logger = static function (string $message): void { };
        $dir = $this->pmssMakeTempDir('pmss-boot-defaults-serial-', 0700);

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
    }

    public function testFstabMutationCharacterizationMatrix(): void
    {
        $cases = [
            ['label' => 'quota', 'expect' => "UUID=abc\t/home\text4\tdefaults,noatime,usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1\t0\t0\n", 'log' => 'Updated quota options for /home', 'runner' => function (string $dir, callable $logger): string {
                $fstab = $dir.'/fstab'; file_put_contents($fstab, "UUID=abc /home ext4 defaults,noatime 0 0\n");
                \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);
                return (string) file_get_contents($fstab);
            }],
            ['label' => 'noexec', 'expect' => "tmpfs\t/tmp\ttmpfs\tdefaults,noexec,nosuid,nodev\t0\t0\ntmpfs\t/dev/shm\ttmpfs\tdefaults,noexec,nosuid,nodev\t0\t0\n", 'log' => 'Updated /tmp mount options', 'runner' => function (string $dir, callable $logger): string {
                $fstab = $dir.'/fstab'; $mounts = $dir.'/mounts';
                file_put_contents($fstab, "tmpfs /tmp tmpfs defaults,exec,suid,dev 0 0\n"."tmpfs /dev/shm tmpfs defaults,exec,suid,dev 0 0\n");
                file_put_contents($mounts, "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n"."tmpfs /dev/shm tmpfs rw,exec,suid,dev 0 0\n");
                putenv('PMSS_HARDEN_TMP_NOEXEC=1'); putenv('PMSS_DRY_RUN=1');
                \pmssConfigureTempMountNoexec($logger, $fstab, $mounts);
                return (string) file_get_contents($fstab);
            }],
            ['label' => 'tmpfs', 'expect' => "UUID=abc / ext4 defaults 0 0\ntmpfs /tmp tmpfs defaults,noexec,nosuid,nodev,size=2G 0 0\n", 'log' => 'Added /tmp tmpfs entry', 'runner' => function (string $dir, callable $logger): string {
                $fstab = $dir.'/fstab'; $mounts = $dir.'/mounts'; file_put_contents($fstab, "UUID=abc / ext4 defaults 0 0\n"); file_put_contents($mounts, '');
                putenv('PMSS_HARDEN_TMP_TMPFS=1'); putenv('PMSS_DRY_RUN=1');
                \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);
                return (string) file_get_contents($fstab);
            }],
            ['label' => 'boot-defaults', 'expect' => "proc\t/proc\tproc\tdefaults,hidepid=2\t0\t0\n", 'log' => 'Updated /proc mount options', 'runner' => function (string $dir, callable $logger): string {
                $fstab = $dir.'/fstab'; $grub = $dir.'/grub'; file_put_contents($fstab, "proc /proc proc defaults,hidepid=1 0 0\n"); file_put_contents($grub, "GRUB_CMDLINE_LINUX_DEFAULT=\"quiet systemd.unified_cgroup_hierarchy=0\"\n");
                \pmssEnsureBootDefaults($logger, $fstab, $grub, 'systemd.unified_cgroup_hierarchy=0');
                return (string) file_get_contents($fstab);
            }],
        ];

        foreach ($cases as $case) {
            $messages = []; $logger = $this->pmssMakeArrayLogger($messages);
            $dir = $this->pmssMakeTempDir('pmss-fstab-char-'.$case['label'].'-', 0700);
            $this->assertSame($case['expect'], $case['runner']($dir, $logger), 'case '.$case['label'].' fstab');
            $this->assertTrue($this->pmssMessagesContain($messages, $case['log']), 'case '.$case['label'].' log');
            foreach (['PMSS_HARDEN_TMP_NOEXEC', 'PMSS_HARDEN_TMP_TMPFS', 'PMSS_DRY_RUN'] as $key) putenv($key);
        }
    }

}
