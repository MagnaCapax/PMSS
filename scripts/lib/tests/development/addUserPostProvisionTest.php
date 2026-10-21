<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/add/postProvision.php';

final class AddUserPostProvisionTest extends TestCase
{
    public function testOpenClawProfileRequiresRamQuotaHeadroomAndAbsentState(): void
    {
        $home = $this->tempDir.'/alice';
        mkdir($home.'/bin', 0700, true);
        file_put_contents($home.'/bin/install-openclaw', '<?php');
        $user = array('name' => 'alice', 'memory' => 2048, 'appProfile' => 'openclaw');
        $files = array('used' => 100, 'soft' => 60100, 'hard' => 75125);
        $this->assertSame(null, \pmssAddUserOpenClawGate($user, $home, $files));
        $small = $user;
        $small['memory'] = 2047;
        $this->assertStringContainsString('RAM', \pmssAddUserOpenClawGate($small, $home, $files));
        $this->assertStringContainsString('quota', \pmssAddUserOpenClawGate($user, $home, null));
        $files['used']++;
        $this->assertStringContainsString('quota', \pmssAddUserOpenClawGate($user, $home, $files));
        $files['used']--;
        mkdir($home.'/.openclaw');
        $this->assertStringContainsString('existing', \pmssAddUserOpenClawGate($user, $home, $files));
        rmdir($home.'/.openclaw');
        symlink($home.'/absent', $home.'/.openclaw');
        $this->assertStringContainsString('existing', \pmssAddUserOpenClawGate($user, $home, $files));
        unlink($home.'/.openclaw');
        unlink($home.'/bin/install-openclaw');
        $this->assertStringContainsString('installer', \pmssAddUserOpenClawGate($user, $home, $files));
        unset($user['appProfile']);
        $this->assertSame('profile not selected', \pmssAddUserOpenClawGate($user, $home, $files));
    }

    public function testOpenClawSkeletonInstallerIsLoadedOnlyWhenTheProfileIsUsed(): void
    {
        // A node without the skeleton installer must still create ordinary accounts.
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/user/add/appProfile.php');
        $loader = strpos($source, 'function pmssAddUserOpenClawInstallerLoad');
        $require = strpos($source, 'require_once $installer;');
        $guard = strpos($source, 'if (!is_file($installer) || is_link($installer)) return false;');
        // The only require sits inside the loader, after the missing-file guard.
        $this->assertTrue($loader !== false && $guard !== false && $require !== false && $loader < $guard && $guard < $require);
        $this->assertSame(1, substr_count($source, 'require_once'.' $installer;') + substr_count($source, "require_once dirname(__DIR__, 4)"));
    }

    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-add-user-bonus-']; }

    public function testTrafficSeedingDelegatesToSharedStorageHelper(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/user/add/postProvision.php',
            [
                "pmssTrafficSeedInitialState(\$user['name'], dirname(\$homePath), null, 'logProvisionMessage')",
                "logProvisionMessage('Seeded traffic files with zero values')",
            ]
        );
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/user/add/postProvision.php', ['pmssTrafficWriteFile(', 'pmssEnsureSafeDir($runtimeStatsDir, 0755)']);
    }

    public function testQuotaMetadataStepsSelectRegularFileEntry(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/add/postProvision.php',
            "runProvisionStep('Seed quota file', 'php /scripts/cron/updateQuotas.php')");
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/user/add/postProvision.php', [
            'find %s -maxdepth 0 -type f -links 1 -exec chmod 640 {} +',
            'find %s -maxdepth 0 -type f -links 1 -exec chown -h %s {} +',
        ]);
    }

    public function testBonusQuotaPersistenceIsOptionalAtomicAndSymlinkSafe(): void
    {
        $home = $this->tempDir.'/alice';
        $this->pmssEnsureDir($home);

        $this->assertTrue(\pmssAddUserBonusQuotaPersist(['name' => 'alice'], $home));
        $this->assertFalse(file_exists($home.'/.bonusQuota'));
        $this->assertTrue(\pmssAddUserBonusQuotaPersist(['name' => 'alice', 'bonusQuotaGiB' => 0], $home));
        $this->assertFalse(file_exists($home.'/.bonusQuota'));
        $this->assertFalse(\pmssAddUserBonusQuotaPersist(['name' => 'alice', 'bonusQuotaGiB' => -1], $home));
        $this->assertFalse(\pmssAddUserBonusQuotaPersist(['name' => 'alice', 'bonusQuotaGiB' => '25'], $home));

        $this->assertTrue(\pmssAddUserBonusQuotaPersist(['name' => 'alice', 'bonusQuotaGiB' => 25], $home));
        $this->assertSame('25', trim((string) file_get_contents($home.'/.bonusQuota')));
        $this->assertSame(0640, fileperms($home.'/.bonusQuota') & 0777);

        unlink($home.'/.bonusQuota');
        $outside = $this->tempDir.'/outside';
        file_put_contents($outside, 'unchanged');
        symlink($outside, $home.'/.bonusQuota');
        $this->assertFalse(\pmssAddUserBonusQuotaPersist(['name' => 'alice', 'bonusQuotaGiB' => 50], $home));
        $this->assertSame('unchanged', file_get_contents($outside));
    }

    public function testPikkumiesPreinstallNeedsTheRamFloorAndTheSkeletonInstaller(): void
    {
        // The slice launcher resolves the uid with `id -u`; a stub keeps the test independent of the host's users.
        $name = 'alice';
        $this->pmssEnsureDir($this->tempDir.'/stub');
        file_put_contents($this->tempDir.'/stub/id', "#!/bin/sh\necho 4321\n");
        chmod($this->tempDir.'/stub/id', 0755);
        $path = (string) getenv('PATH');
        $home = $this->tempDir.'/home';
        $this->pmssEnsureDir($home.'/bin');
        $floor = \pmssUserPikkumiesPreinstallMinRamMiB();
        $this->assertSame(400, $floor);
        putenv('PATH='.$this->tempDir.'/stub:'.$path);
        try {
            $openclaw = \pmssAddUserOpenClawPreinstallCommand($name);
            $this->assertStringContainsAllStrings(array('systemd-run', '--slice=user-4321.slice', 'su', 'alice',
                'php "$HOME/bin/install-openclaw" install --yes', 'umask 077', '.install-openclaw.log'), $openclaw);
            $noInstaller = \pmssAddUserPikkumiesPreinstallCommand(['name' => $name, 'memory' => (string) $floor], $home);
            file_put_contents($home.'/bin/install-pikkumies.sh', "#!/usr/bin/env bash\n");
            $belowFloor = \pmssAddUserPikkumiesPreinstallCommand(['name' => $name, 'memory' => (string) ($floor - 1)], $home);
            $command = (string) \pmssAddUserPikkumiesPreinstallCommand(['name' => $name, 'memory' => (string) $floor], $home);
        } finally {
            putenv('PATH='.$path);
        }

        $this->assertTrue($noInstaller === null, 'no installer in ~/bin');
        $this->assertTrue($belowFloor === null, 'below the RAM floor');
        foreach (['nohup sh -c ', 'systemd-run', '--slice=user-', 'PIKKUMIES_SKIP_HERMES=1', 'install-pikkumies.sh', "' >/dev/null 2>&1 &"] as $part) {
            $this->assertTrue(strpos($command, $part) !== false, "preinstall command lacks $part: $command");
        }
        $this->assertTrue(strpos($command, '--slice=user-4321.slice') !== false, 'not the new user\'s slice: '.$command);
        $this->assertTrue(strpos($command, '--accept-terms') === false, 'the terms are the person\'s to accept at the first session');
    }
}
