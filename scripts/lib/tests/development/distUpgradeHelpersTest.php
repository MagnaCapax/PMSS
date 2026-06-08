<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/distUpgrade.php';

class DistUpgradeHelpersTest extends TestCase
{
    /** @var string */
    private $tmpDir;

    public function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tmpDir', 'pmss-dist-upgrade-helpers-');
    }

    public function testDistUpgradeFacadeLoadsDecomposedHelperSurface(): void
    {
        foreach ([
            'pmssRunDistUpgrade',
            'pmssResolveDistUpgradeStep',
            'pmssDistUpgradeAptCommand',
            'pmssExecuteUpgrade',
            'pmssRewriteSources',
            'pmssEnsureFuseOverlayfsAfterDistUpgrade',
            'pmssRepairDockerRootlessAfterDistUpgrade',
            'pmssVerifyDistUpgradeBootReadiness',
        ] as $function) {
            $this->assertTrue(\function_exists($function), 'Missing dist-upgrade helper: '.$function);
        }

        $this->assertSame(
            ['action' => 'upgrade', 'from' => '10', 'to' => '11', 'message' => 'Requested maximum is 12; performing safe incremental upgrade to 11.'],
            \pmssResolveDistUpgradeStep('10', '12')
        );
    }

    public function testResolveTargetVersionAcceptsNumbersAndCodenames(): void
    {
        foreach ([
            '10' => '10', 'buster' => '10', 'bullseye' => '11', 'Bookworm' => '12', 'TRIXIE' => '13',
            'jessie' => '', 'stretch' => '', '8' => '', '9' => '', '10 ' => '', '' => '', 'nonesuch' => '',
        ] as $input => $expected) {
            $this->assertEquals($expected, \pmssResolveTargetVersion((string) $input), 'target '.$input);
        }
    }

    public function testResolveDistUpgradeStepHonorsMaximum(): void
    {
        $cases = [
            ['10', '12', ['action' => 'upgrade', 'from' => '10', 'to' => '11', 'message' => 'Requested maximum is 12; performing safe incremental upgrade to 11.']],
            ['11', '13', ['action' => 'upgrade', 'from' => '11', 'to' => '12', 'message' => 'Requested maximum is 13; performing safe incremental upgrade to 12.']],
            ['12', '13', ['action' => 'upgrade', 'from' => '12', 'to' => '13', 'message' => '']],
            ['11', '11', ['action' => 'noop', 'from' => '11', 'to' => null, 'message' => 'No dist-upgrade required: current version is 11 and requested maximum is 11.']],
            ['13', '13', ['action' => 'noop', 'from' => '13', 'to' => null, 'message' => 'No dist-upgrade required: current version is 13 and requested maximum is 13.']],
            ['9', '13', ['action' => 'noop', 'from' => null, 'to' => null, 'message' => 'No upgrade recipe for Debian 9']],
            ['13', '14', ['action' => 'noop', 'from' => null, 'to' => null, 'message' => 'No upgrade recipe for Debian 13']],
            ['12', '11', ['action' => 'error', 'from' => '12', 'to' => null, 'message' => 'Safety halt: Current version is 12 but the requested maximum is 11.']],
            ['13', '12', ['action' => 'error', 'from' => '13', 'to' => null, 'message' => 'Safety halt: Current version is 13 but the requested maximum is 12.']],
            ['14', '13', ['action' => 'error', 'from' => '14', 'to' => null, 'message' => 'Safety halt: Current version is 14 but the requested maximum is 13.']],
        ];

        foreach ($cases as [$current, $max, $expected]) {
            $plan = \pmssResolveDistUpgradeStep($current, $max);
            $this->assertSame($expected, $plan, "plan for {$current}/{$max}");
        }
    }

    public function testDistUpgradeAptCommandPreservesKnownActions(): void
    {
        $env = 'DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none';
        $opts = ' -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';

        $this->assertSame(
            $env.' apt-get install'.$opts.' fuse-overlayfs nginx-full',
            \pmssDistUpgradeAptCommand($env, 'install', 'fuse-overlayfs nginx-full')
        );
        $this->assertSame($env.' apt-get upgrade'.$opts, \pmssDistUpgradeAptCommand($env, 'upgrade'));
        $this->assertSame($env.' apt-get full-upgrade'.$opts, \pmssDistUpgradeAptCommand($env, 'full-upgrade'));
    }

    public function testDistUpgradeAptEnvCarriesUnattendedRecoveryVariables(): void
    {
        [$env, $hasTty] = \pmssDistUpgradeAptEnv(false);

        $this->assertEquals(false, $hasTty);
        $this->assertStringContainsAllStrings([
            'DEBIAN_FRONTEND=noninteractive',
            'APT_LISTCHANGES_FRONTEND=none',
            'UCF_FORCE_CONFDEF=1',
            'UCF_FORCE_CONFOLD=1',
            'NEEDRESTART_MODE=a',
        ], $env);
    }

    public function testDistUpgradeAptCommandRejectsShellShapedInput(): void
    {
        $this->assertDistUpgradeAptCommandFails('install; reboot', 'libcrypt1', 'Unsafe dist-upgrade apt action');
        $this->assertDistUpgradeAptCommandFails('install', 'libcrypt1; reboot', 'Unsafe dist-upgrade apt arguments');
        $this->assertDistUpgradeAptCommandFails('install', "libcrypt1\nnginx", 'Unsafe dist-upgrade apt arguments');
        $this->assertDistUpgradeAptCommandFails('install', '/tmp/package.deb', 'Unsafe dist-upgrade apt arguments');
    }

    public function testDistUpgradeLockedCommandMessagesStayStable(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/distUpgrade/apt.php' => ['required' => [
                '[ERROR] dist-upgrade: dpkg lock did not clear; aborting apt phase',
                '[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt action',
                '[ERROR] dist-upgrade: dpkg lock did not clear; skipping dpkg recovery',
                '[WARN] dist-upgrade: dpkg lock did not clear; skipping libcrypt1 install',
            ]],
            'scripts/lib/update/distUpgrade/boot.php' => ['required' => [
                '[WARN] dist-upgrade: dpkg lock did not clear; skipping nginx reinstall',
            ]],
        ]);
    }

    public function testBootReadinessParsers(): void
    {
        $cases = [
            ['', false],
            ["Personalities : [raid1]\nunused devices: <none>\n", false],
            ["md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/2] [UU]\n", false],
            ["md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/1] [U_]\n", true],
            ["md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/1] [_U]\n", true],
            ["md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/2] [UU]\nmd1 : active raid1 sdc1[0] sdd1[1]\n      104320 blocks [2/1] [U_]\n", true],
        ];

        foreach ($cases as [$mdstat, $expected]) {
            $this->assertEquals($expected, \pmssMdstatHasDegradedArrays($mdstat));
        }
    }

    public function testVerifyDistUpgradeBootReadinessReportsConfigStates(): void
    {
        foreach ([
            [
                "md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/2] [UU]\n",
                str_repeat('menuentry test\n', 100),
                "ARRAY /dev/md0 metadata=1.2 UUID=abc\n",
                "BOOT_DEGRADED=true\n",
                [
                    '[SKIP] dist-upgrade: RAID arrays appear healthy',
                    '[SKIP] dist-upgrade: grub config present',
                    '[SKIP] dist-upgrade: mdadm ARRAY definitions found',
                    '[SKIP] dist-upgrade: BOOT_DEGRADED=true is configured',
                ],
            ],
            [
                "md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/1] [U_]\n",
                str_repeat('menuentry test\n', 100),
                "DEVICE partitions\nMAILADDR root\n",
                "BOOT_DEGRADED=false\n",
                [
                    'degraded RAID array detected',
                    'lacks ARRAY definitions',
                    'missing BOOT_DEGRADED=true',
                ],
            ],
        ] as [$mdstat, $grub, $mdadmConfig, $initramfsMdadm, $needles]) {
            $this->assertStringContainsAllStrings($needles, $this->captureBootReadinessOutput($mdstat, $grub, $mdadmConfig, $initramfsMdadm));
        }
    }

    private function captureBootReadinessOutput(
        string $mdstat,
        string $grub,
        string $mdadmConfig,
        string $initramfsMdadm
    ): string {
        $mdstatPath = $this->tmpDir.'/mdstat';
        $grubPath = $this->tmpDir.'/grub.cfg';
        $mdadmConfigPath = $this->tmpDir.'/mdadm.conf';
        $initramfsMdadmPath = $this->tmpDir.'/initramfs-mdadm';

        file_put_contents($mdstatPath, $mdstat);
        file_put_contents($grubPath, $grub);
        file_put_contents($mdadmConfigPath, $mdadmConfig);
        file_put_contents($initramfsMdadmPath, $initramfsMdadm);

        list(, $output) = $this->pmssCaptureStdout(function () use ($mdstatPath, $grubPath, $mdadmConfigPath, $initramfsMdadmPath): void {
            \pmssVerifyDistUpgradeBootReadiness($mdstatPath, $grubPath, $mdadmConfigPath, $initramfsMdadmPath);
        });

        return $output;
    }

    private function assertDistUpgradeAptCommandFails(string $action, string $arguments, string $message): void
    {
        try {
            \pmssDistUpgradeAptCommand('DEBIAN_FRONTEND=noninteractive', $action, $arguments);
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            return;
        }

        $this->fail('Expected dist-upgrade apt command guard failure: '.$message);
    }
}
